<?php

namespace Database\Factories;

use App\Enums\RepairLogEvent;
use App\Enums\RepairStatus;
use App\Models\Repair;
use App\Models\RepairStatusLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepairStatusLog>
 */
class RepairStatusLogFactory extends Factory
{
    protected $model = RepairStatusLog::class;

    public function definition(): array
    {
        return [
            'repair_id' => Repair::factory(),
            'event_type' => RepairLogEvent::StatusChange,
            'from_status' => RepairStatus::Recibido,
            'to_status' => RepairStatus::Cotizado,
            'changed_by' => User::factory(),
            'metadata' => null,
            'note' => null,
            'created_at' => now(),
        ];
    }

    public function statusChange(RepairStatus $from, RepairStatus $to): static
    {
        return $this->state(fn () => [
            'event_type' => RepairLogEvent::StatusChange,
            'from_status' => $from,
            'to_status' => $to,
        ]);
    }
}
