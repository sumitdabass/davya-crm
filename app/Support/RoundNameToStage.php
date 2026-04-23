<?php

namespace App\Support;

/**
 * Maps external round-name identifiers (e.g. "Online_R1", "Offline_R2") to
 * canonical stage names. Used by ActivityDescriber when logging round events —
 * the round name is what the external system emits, the stage name is what we
 * surface in the UI.
 *
 * This is a domain helper, not pipeline config: the mapping doesn't change per
 * pipeline and has nothing to do with whether a stage is OPEN/CLOSED_WON/LOST.
 */
class RoundNameToStage
{
    public static function stageName(string $roundName): ?string
    {
        return match ($roundName) {
            'Online_R1', 'S2_R1' => 'Round 1',
            'Online_R2'          => 'Round 2',
            'Online_R3', 'S2_R3' => 'Round 3',
            'Online_Sliding', 'Online_Reporting' => 'Sliding',
            'Offline_R1', 'Offline_R2' => 'Offline',
            default => null,
        };
    }
}
