<?php

return [
    'terminal_stages' => ['Admission Confirmed', 'Closed'],

    'tiers' => [
        ['min' => 85, 'label' => 'Star'],
        ['min' => 70, 'label' => 'Strong'],
        ['min' => 55, 'label' => 'Solid'],
        ['min' => 40, 'label' => 'Growth'],
        ['min' =>  0, 'label' => 'Coaching'],
    ],

    'weights' => [
        'closed_won'        => 0.25,
        'deal_won_amount'   => 0.25,
        'rank_prob_avg'     => 0.15,
        'advance_received'  => 0.10,
        'conversion_rate'   => 0.10,
        'meeting_win_rate'  => 0.05,
        'pipeline_health'   => 0.10,
    ],

    'pipeline_health' => [
        'balance_penalty_factor' => 30,
        'balance_penalty_cap'    => 50,
        'stale_penalty_per_lead' => 5,
        'stale_penalty_cap'      => 20,
        'open_bonus_per_two'     => 1,
        'open_bonus_cap'         => 10,
    ],

    'min_sample_floor' => 3,

    'stale_threshold_days' => 60,
];
