<?php

namespace OpenDominion\Services\Dominion\Actions;

use DB;
use OpenDominion\Calculators\Dominion\Actions\ExplorationCalculator;
use OpenDominion\Exceptions\GameException;
use OpenDominion\Helpers\LandHelper;
use OpenDominion\Models\Dominion;
use OpenDominion\Services\Dominion\HistoryService;
use OpenDominion\Services\Dominion\QueueService;
use OpenDominion\Traits\DominionGuardsTrait;

class ExploreActionService
{
    use DominionGuardsTrait;

    /** @var ExplorationCalculator */
    protected $explorationCalculator;

    /** @var LandHelper */
    protected $landHelper;

    /** @var QueueService */
    protected $queueService;

    /**
     * ExplorationActionService constructor.
     */
    public function __construct()
    {
        $this->explorationCalculator = app(ExplorationCalculator::class);
        $this->landHelper = app(LandHelper::class);
        $this->queueService = app(QueueService::class);
    }

    /**
     * Does an explore action for a Dominion.
     *
     * @param Dominion $dominion
     * @param array $data
     * @return array
     * @throws GameException
     */
    public function explore(Dominion $dominion, array $data): array
    {
        $this->guardLockedDominion($dominion);

        if($dominion->round->hasOffensiveActionsDisabled())
        {
            throw new GameException('Exploration has been disabled for the remainder of the round.');
        }

        $data = array_only($data, array_map(function ($value) {
            return "land_{$value}";
        }, $this->landHelper->getLandTypes()));

        $data = array_map('\intval', $data);

        $totalLandToExplore = array_sum($data);

        if ($totalLandToExplore === 0) {
            throw new GameException('Exploration was not begun due to bad input.');
        }

        $maxAfford = $this->explorationCalculator->getMaxAfford($dominion);

        if ($totalLandToExplore > $maxAfford) {
            throw new GameException("You do not have enough platinum and/or draftees to explore for {$totalLandToExplore} acres.");
        }

        // todo: refactor. see training action service. same with other action services
        $newMorale = max(0, ($dominion->morale - $this->explorationCalculator->getMoraleDrop($totalLandToExplore)));
        $moraleDrop = ($dominion->morale - $newMorale);

        $platinumCost = ($this->explorationCalculator->getPlatinumCost($dominion) * $totalLandToExplore);
        $newPlatinum = ($dominion->resource_platinum - $platinumCost);

        $drafteeCost = ($this->explorationCalculator->getDrafteeCost($dominion) * $totalLandToExplore);
        $newDraftees = ($dominion->military_draftees - $drafteeCost);

        DB::transaction(function () use ($dominion, $data, $newMorale, $newPlatinum, $newDraftees) {
            $dominion->fill([
                'morale' => $newMorale,
                'resource_platinum' => $newPlatinum,
                'military_draftees' => $newDraftees,
            ])->save(['event' => HistoryService::EVENT_ACTION_EXPLORE]);

            $this->queueService->queueResources('exploration', $dominion, $data);
        });

        return [
            'message' => sprintf(
                'Exploration begun at a cost of %s platinum and %s %s. Your orders for exploration disheartens the military, and morale drops %d%%.',
                number_format($platinumCost),
                number_format($drafteeCost),
                str_plural('draftee', $drafteeCost),
                $moraleDrop
            ),
            'data' => [
                'platinumCost' => $platinumCost,
                'drafteeCost' => $drafteeCost,
                'moraleDrop' => $moraleDrop,
            ]
        ];
    }
}
