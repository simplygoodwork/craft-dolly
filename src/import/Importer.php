<?php

namespace simplygoodwork\craftportage\import;

use Craft;
use Throwable;

/**
 * Executes an ImportPlan, one transaction per Root element.
 *
 * New entries are saved disabled, so required fields left empty by skipped values don't fail the save. A Root
 * element that is blocked or can't be saved fails on its own without affecting the others.
 */
final class Importer
{
    /**
     * @return list<RootResult>
     */
    public function import(ImportPlan $plan): array
    {
        return array_map(fn(RootPlan $root) => $this->importRoot($root), $plan->roots);
    }

    private function importRoot(RootPlan $plan): RootResult
    {
        $result = RootResult::fromPlan($plan);
        $entry = $plan->entry;

        if ($plan->isBlocked() || !$entry) {
            $result->fail($plan->blockedBy ?? 'The Root element can\'t be imported.');
            return $result;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            if (!Craft::$app->getElements()->saveElement($entry)) {
                $transaction->rollBack();
                $result->fail('Craft couldn\'t save the entry: ' . implode(' ', $entry->getFirstErrors()));
                return $result;
            }
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            Craft::$app->getErrorHandler()->logException($e);
            $result->fail('Couldn\'t import the entry: ' . $e->getMessage());
            return $result;
        }

        $result->elementId = $entry->id;

        return $result;
    }
}
