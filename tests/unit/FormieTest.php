<?php

namespace simplygoodwork\craftdolly\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\services\Dolly;
use simplygoodwork\craftdolly\tests\support\Schema;
use verbb\formie\elements\Form;
use verbb\formie\fields\Forms;

/**
 * Formie's Forms field, a relation field to Formie forms, which Dolly registers as the element type `formieForm`.
 * Forms aren't in project config, so a form usually matches by its handle rather than its UID.
 */
class FormieTest extends Unit
{
    public function testAFormMatchesByUidOrElseByItsHandle(): void
    {
        $contact = Schema::form('Contact', 'contact');
        $newsletter = Schema::form('Newsletter', 'newsletter');
        $newsletterSourceUid = $newsletter->uid;
        $article = Schema::entryType('article', [Schema::field(Forms::class, 'forms')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Get in touch', 'forms' => [$contact->id, $newsletter->id]]);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        Schema::changeUid($newsletter);

        $plan = $this->dolly()->preview($payload);
        $this->assertSame('formieForm', $plan->references[$contact->uid]->elementType);
        $this->assertSame(['handle' => 'newsletter'], $plan->references[$newsletterSourceUid]->reference['naturalKey']);
        $this->assertSame('uid', $plan->references[$contact->uid]->resolution->rule);
        $this->assertSame('naturalKey', $plan->references[$newsletterSourceUid]->resolution->rule);

        $result = $this->dolly()->import($payload);

        $this->assertSame([], $result->roots[0]->warnings);
        $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
        $this->assertSame([$contact->id, $newsletter->id], $imported->getFieldValue('forms')->status(null)->ids());
    }

    public function testAnUnresolvedFormCanBeSkippedOrReplacedButNotStubbed(): void
    {
        $gone = Schema::form('Gone', 'gone');
        $goneUid = $gone->uid;
        $contact = Schema::form('Contact', 'contact');
        $article = Schema::entryType('article', [Schema::field(Forms::class, 'forms')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Get in touch', 'forms' => [$gone->id]]);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($gone);

        $this->assertSame(['skip', 'replace'], $this->dolly()->preview($payload)->references[$goneUid]->availableChoices);
        $this->assertSame(
            'The Reference "Gone" can\'t be Stubbed: Dolly never Stubs elements. Skip it or choose a replacement.',
            $this->dolly()->preview($payload, [$goneUid => 'stub'])->refusal,
        );

        $result = $this->dolly()->import($payload, [$goneUid => $contact->id]);

        $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
        $this->assertSame([$contact->id], $imported->getFieldValue('forms')->status(null)->ids());
        $this->assertNull(Form::find()->uid($goneUid)->status(null)->one());
    }

    public function testAnUnresolvedFormThatIsSkippedIsLeftOutOfTheField(): void
    {
        $gone = Schema::form('Gone', 'gone');
        $goneUid = $gone->uid;
        $contact = Schema::form('Contact', 'contact');
        $article = Schema::entryType('article', [Schema::field(Forms::class, 'forms')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Get in touch', 'forms' => [$gone->id, $contact->id]]);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($gone);

        $result = $this->dolly()->import($payload, [$goneUid => 'skip']);

        $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
        $this->assertSame([$contact->id], $imported->getFieldValue('forms')->status(null)->ids());
    }

    public function testAnImportFromTheControlPanelRelatesAFormItsUserCannotViewInFormie(): void
    {
        $contact = Schema::form('Contact', 'contact');
        $article = Schema::entryType('article', [Schema::field(Forms::class, 'forms')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Get in touch', 'forms' => [$contact->id]]);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        $editor = Schema::user('editor');
        Schema::grantPermissions($editor, ['accessCp', "viewEntries:$news->uid", "saveEntries:$news->uid", "createEntries:$news->uid"]);
        Craft::$app->getUser()->setIdentity($editor);

        $result = Schema::asControlPanelRequest(function() use ($payload, $contact) {
            $plan = $this->dolly()->preview($payload);
            $this->assertSame('uid', $plan->references[$contact->uid]->resolution->rule);

            return $this->dolly()->import($payload);
        });

        $this->assertSame([], $result->roots[0]->warnings);
        $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
        $this->assertSame([$contact->id], $imported->getFieldValue('forms')->status(null)->ids());
    }

    private function dolly(): Dolly
    {
        return Plugin::getInstance()->getDolly();
    }
}
