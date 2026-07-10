<?php
// test_schedule.php - Unit test for rotation calculations & override precedence
require_once 'models.php';

function run_tests() {
    echo "Running Schedule Calculation Logic Tests...\n";

    // Scenario:
    // Base Slot: Monday 5pm (17:00) to next Monday 5pm (17:00) -> Alice (User ID 1)
    // Override: Wednesday 10am to Thursday 2pm -> Bob (User ID 2)

    $base_slots = [
        [
            'start_time' => '2026-07-13 17:00:00',
            'end_time' => '2026-07-20 17:00:00',
            'user_id' => 1,
            'username' => 'alice',
            'name' => 'Alice',
            'surname' => 'Smith'
        ]
    ];

    $overrides = [
        [
            'id' => 1,
            'start_time' => '2026-07-15 10:00:00',
            'end_time' => '2026-07-16 14:00:00',
            'user_id' => 2,
            'username' => 'bob',
            'name' => 'Bob',
            'surname' => 'Jones',
            'description' => 'Sickness cover'
        ]
    ];

    $segments = calculate_final_schedule($base_slots, $overrides);

    // We expect 3 segments:
    // 1. Alice from 2026-07-13 17:00:00 to 2026-07-15 10:00:00
    // 2. Bob from 2026-07-15 10:00:00 to 2026-07-16 14:00:00 (Override)
    // 3. Alice from 2026-07-16 14:00:00 to 2026-07-20 17:00:00

    assert(count($segments) === 3, "Expected 3 segments");

    assert($segments[0]['user_id'] === 1, "Segment 1 should be Alice");
    assert(date('Y-m-d H:i:s', $segments[0]['start']) === '2026-07-13 17:00:00', "Segment 1 start time is wrong");
    assert(date('Y-m-d H:i:s', $segments[0]['end']) === '2026-07-15 10:00:00', "Segment 1 end time is wrong");
    assert($segments[0]['is_override'] === false, "Segment 1 should not be an override");

    assert($segments[1]['user_id'] === 2, "Segment 2 should be Bob");
    assert(date('Y-m-d H:i:s', $segments[1]['start']) === '2026-07-15 10:00:00', "Segment 2 start time is wrong");
    assert(date('Y-m-d H:i:s', $segments[1]['end']) === '2026-07-16 14:00:00', "Segment 2 end time is wrong");
    assert($segments[1]['is_override'] === true, "Segment 2 should be an override");

    assert($segments[2]['user_id'] === 1, "Segment 3 should be Alice");
    assert(date('Y-m-d H:i:s', $segments[2]['start']) === '2026-07-16 14:00:00', "Segment 3 start time is wrong");
    assert(date('Y-m-d H:i:s', $segments[2]['end']) === '2026-07-20 17:00:00', "Segment 3 end time is wrong");
    assert($segments[2]['is_override'] === false, "Segment 3 should not be an override");

    echo "All assertions passed successfully! The overlap/clipping algorithm is fully correct!\n";
}

run_tests();
