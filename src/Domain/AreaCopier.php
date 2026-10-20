<?php

declare(strict_types=1);

namespace MKTodo\Domain;

use WP_Error;

/**
 * Copies an area: "Praca" → "Praca (kopia)" right below it, with copies of its projects (name, colour, order)
 * and, if asked, of their open tasks. Done tasks are history – they stay with the original.
 */
final class AreaCopier
{
    public function __construct(
        private readonly AreaRepository $areas,
        private readonly ProjectRepository $projects,
        private readonly TaskRepository $tasks,
    ) {
    }

    /** @return array<string, mixed>|WP_Error the new area */
    public function copy(int $id, bool $withTasks): array|WP_Error
    {
        $area = $this->areas->find($id);

        if (is_wp_error($area)) {
            return $area;
        }

        $copy = $this->areas->create($this->areas->copyName($area['name']), $area['icon']);

        if (is_wp_error($copy)) {
            return $copy;
        }

        // right below the original, not at the end
        $order = array_values(array_diff(array_column($this->areas->all(), 'id'), [$copy['id']]));
        array_splice($order, (int) array_search($id, $order, true) + 1, 0, [$copy['id']]);
        $this->areas->reorder($order);

        foreach ($this->projects->all() as $project) {
            if ($project['areaId'] !== $id) {
                continue;
            }

            $duplicate = $this->projects->duplicate($project, $copy['id']);

            if (is_wp_error($duplicate)) {
                return $duplicate;
            }

            if ($withTasks && is_wp_error($result = $this->tasks->copyOpen($project['id'], $duplicate['id']))) {
                return $result;
            }
        }

        return $this->areas->find($copy['id']);
    }
}
