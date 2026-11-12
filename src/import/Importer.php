<?php

namespace simplygoodwork\craftportage\import;

use Craft;
use craft\base\ElementInterface;
use Throwable;
use yii\db\Transaction;

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

        $created = [];
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            foreach ($plan->stubs as $reference) {
                $stub = $reference->stub;
                // A Stub shared with an earlier Root element is already saved.
                if (!$stub || $stub->id !== null) {
                    continue;
                }
                if (!Craft::$app->getElements()->saveElement($stub)) {
                    $this->rollBack($transaction, $created);
                    $result->fail("Craft couldn't create the Stub for {$reference->name()}: " . implode(' ', $stub->getFirstErrors()));
                    return $result;
                }
                $created[] = $stub;
                $result->stubs[] = [
                    'uid' => $reference->uid,
                    'elementType' => $reference->elementType,
                    'title' => $reference->title,
                    'elementId' => (int)$stub->id,
                ];
            }

            if (!Craft::$app->getElements()->saveElement($entry)) {
                $this->rollBack($transaction, $created);
                $result->fail('Craft couldn\'t save the entry: ' . implode(' ', $entry->getFirstErrors()));
                return $result;
            }
            $transaction->commit();
        } catch (Throwable $e) {
            $this->rollBack($transaction, $created);
            Craft::$app->getErrorHandler()->logException($e);
            $result->fail('Couldn\'t import the entry: ' . $e->getMessage());
            return $result;
        }

        $result->elementId = $entry->id;

        return $result;
    }

    /**
     * Rolls back a Root element's transaction, and forgets the IDs of the Stubs it created so that a later Root
     * element relating to the same Reference creates the Stub again.
     *
     * @param ElementInterface[] $stubs
     */
    private function rollBack(Transaction $transaction, array $stubs): void
    {
        $transaction->rollBack();
        foreach ($stubs as $stub) {
            $stub->id = null;
        }
    }
}
