<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Models\Building;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Проекты: сравнение зданий-кандидатов и итог.
 *
 * Кандидат – здание проекта с is_candidate: у него свои этажи, подложки и помещения,
 * но настоящих единиц там нет (AssetMover не пускает) – только варианты расстановки
 * проекта. Выбор делает здание настоящим (филиал обязателен), варианты на нем остаются
 * черновиками: утвердят, когда переезд или закупка случатся на деле.
 */
class Projects
{
    /** Новое здание-кандидат проекта. */
    public function addCandidate(Project $project, array $data): Building
    {
        $this->ensureOpen($project);

        return $project->buildings()->create($data + ['is_candidate' => true, 'office_id' => $project->office_id]);
    }

    /**
     * Сравнение: по каждому зданию проекта – сколько штук каждой потребности расставлено
     * в вариантах проекта на его этажах и сколько помещений не подходит по требованиям.
     *
     * @return list<array{building: Building, placed: int, total: int, needs: array<int, array{need: string, placed: int, quantity: int}>, unfit: list<string>}>
     */
    public function compare(Project $project): array
    {
        $project->load(['needs', 'buildings.floors']);
        $layouts = $project->layouts()->with(['items.room', 'items.need', 'floor'])->get();
        $total = (int) $project->needs->sum('quantity');

        // Здания: кандидаты проекта и настоящие, на которых у проекта есть варианты (сравнить с тем, что есть).
        $buildings = $project->buildings->keyBy('id');
        foreach ($layouts as $layout) {
            $buildings[$layout->floor->building_id] ??= $layout->floor->building;
        }

        $rows = [];
        foreach ($buildings as $building) {
            $items = $layouts->filter(fn ($l) => $l->floor->building_id === $building->id)->flatMap->items;
            $needs = [];
            $placed = 0;
            foreach ($project->needs as $need) {
                $n = min($need->quantity, $items->where('need_id', $need->id)->count());
                $placed += $n;
                $needs[$need->id] = ['need' => $need->label, 'placed' => $n, 'quantity' => $need->quantity];
            }

            $unfit = [];
            foreach ($items->whereNotNull('need_id')->whereNotNull('room_id') as $item) {
                $missing = Specs::check($item->need->effectiveRequirements(), $item->room->specs ?? []);
                if ($missing) {
                    $unfit[] = $item->need->label.' – '.$item->room->title.': '.implode(', ', $missing);
                }
            }

            $rows[] = ['building' => $building, 'placed' => $placed, 'total' => $total, 'needs' => $needs, 'unfit' => $unfit];
        }

        return $rows;
    }

    /** Выбрано здание: кандидат становится настоящим зданием филиала. */
    public function choose(Project $project, Building $building, ?int $officeId = null, ?string $decision = null): Project
    {
        $this->ensureOpen($project);
        if ($building->project_id !== $project->id) {
            throw ValidationException::withMessages(['building' => 'Здание не из этого проекта.']);
        }
        $officeId ??= $building->office_id ?? $project->office_id;
        if (! $officeId) {
            throw ValidationException::withMessages(['office_id' => 'Укажите филиал, к которому отнести здание.']);
        }

        return DB::transaction(function () use ($project, $building, $officeId, $decision) {
            $building->update(['is_candidate' => false, 'office_id' => $officeId]);
            $project->update([
                'status' => ProjectStatus::Chosen,
                'chosen_building_id' => $building->id,
                'office_id' => $project->office_id ?? $officeId,
                'decision' => $decision,
                'decided_at' => now(),
                'decided_by' => Auth::id(),
            ]);

            return $project;
        });
    }

    /** Не выбрали ничего: кандидаты остаются в проекте для истории. */
    public function reject(Project $project, ?string $decision = null): Project
    {
        $this->ensureOpen($project);
        $project->update([
            'status' => ProjectStatus::Rejected,
            'decision' => $decision,
            'decided_at' => now(),
            'decided_by' => Auth::id(),
        ]);

        return $project;
    }

    private function ensureOpen(Project $project): void
    {
        if (! $project->isOpen()) {
            throw ValidationException::withMessages(['project' => "Проект уже закрыт: {$project->status->label()}."]);
        }
    }
}
