<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\CorpusManifest;
use AIForge\DevTools\Tests\Unit\TestCase;
use RuntimeException;

class CorpusManifestTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/corpus-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * Nine files, each trap carried by three of them.
     *
     * @return array<string, mixed>
     */
    private function validData(): array
    {
        $traps = [['T1', 'T2'], ['T3', 'T4'], ['T5', 'T6'], ['T1', 'T2'], ['T3', 'T4'], ['T5', 'T6'], ['T1', 'T3'], ['T2', 'T4'], ['T5', 'T6']];
        $files = [];

        foreach ($traps as $i => $pair) {
            $id = sprintf('hard-%02d-page', $i + 1);
            file_put_contents("{$this->dir}/{$id}.md", "# Page\n");
            $files[] = [
                'id' => $id,
                'file' => "{$id}.md",
                'author' => 'claude',
                'locale' => 'fr_FR',
                'sector' => 'artisan',
                'traps' => $pair,
                'figures' => [],
                'quotes' => [],
                'offers' => [],
                'claims' => \in_array('T6', $pair, true) ? ['formations régulières'] : [],
                'grid' => \in_array('T3', $pair, true) ? ['section' => 'Nos ateliers', 'items' => ['A', 'B']] : null,
                'source' => null,
            ];
        }

        return ['version' => 1, 'generic_cta' => ['nous contacter'], 'cta_vocabulary' => [], 'files' => $files];
    }

    private function write(array $data): void
    {
        file_put_contents("{$this->dir}/manifest.json", json_encode($data));
    }

    public function testValidManifestHasNoErrors(): void
    {
        $this->write($this->validData());

        $manifest = CorpusManifest::load($this->dir);

        $this->assertSame([], $manifest->errors());
        $this->assertCount(9, $manifest->entries);
        $this->assertSame('hard-01-page', $manifest->entries[0]->id);
    }

    public function testDuplicateIdsAreReported(): void
    {
        $data = $this->validData();
        $data['files'][1]['id'] = $data['files'][0]['id'];
        $data['files'][1]['file'] = $data['files'][0]['file'];
        $this->write($data);

        $this->assertContains('duplicate id hard-01-page', CorpusManifest::load($this->dir)->errors());
    }

    public function testMissingFileIsReported(): void
    {
        $this->write($this->validData());
        unlink("{$this->dir}/hard-02-page.md");

        $manifest = CorpusManifest::load($this->dir);

        $this->assertContains('hard-02-page: file hard-02-page.md is missing', $manifest->errors());
        $this->assertNull($manifest->markdown($manifest->entries[1]));
    }

    public function testATrapInFewerThanThreeFilesIsReported(): void
    {
        $data = $this->validData();
        $data['files'][8]['traps'] = ['T5'];
        $data['files'][8]['claims'] = [];
        $this->write($data);

        $this->assertContains('trap T6 is carried by 2 file(s), needs at least 3', CorpusManifest::load($this->dir)->errors());
    }

    public function testFieldRulesAreEnforced(): void
    {
        $data = $this->validData();
        $data['files'][0]['author'] = 'mistral';
        $data['files'][1]['grid'] = null;
        $data['files'][2]['claims'] = [];
        $data['files'][3]['author'] = 'adapted';
        $data['files'][4]['quotes'] = [['text' => 'Bien']];
        $data['files'][5]['figures'] = ['beaucoup' => ['beaucoup']];
        $this->write($data);

        $errors = CorpusManifest::load($this->dir)->errors();

        $this->assertContains('hard-01-page: unknown author mistral', $errors);
        $this->assertContains('hard-02-page: T3 needs a grid with a section and exactly 2 items', $errors);
        $this->assertContains('hard-03-page: T6 needs at least one claim', $errors);
        $this->assertContains('hard-04-page: an adapted file needs its source', $errors);
        $this->assertContains('hard-05-page: every quote needs a text and an attribution', $errors);
        $this->assertContains('hard-06-page: figure key beaucoup is not a number', $errors);
    }

    public function testCramCharsMustBeAPositiveInteger(): void
    {
        foreach ([0, -3, 12.5, 'forty'] as $value) {
            $data = $this->validData();
            $data['thresholds'] = ['cram_chars' => $value];
            $this->write($data);

            $this->assertContains('threshold cram_chars must be a positive integer', CorpusManifest::load($this->dir)->errors(), var_export($value, true));
        }

        $data = $this->validData();
        $data['thresholds'] = ['cram_chars' => 40];
        $this->write($data);

        $this->assertSame([], CorpusManifest::load($this->dir)->errors());
    }

    public function testThresholdsAndVocabularyFeedTheSettings(): void
    {
        $data = $this->validData();
        $data['thresholds'] = ['retention' => 0.75];
        $data['cta_vocabulary'] = ['réserver'];
        $this->write($data);

        $settings = CorpusManifest::load($this->dir)->settings;

        $this->assertSame(0.75, $settings->retention);
        $this->assertSame(0.5, $settings->orphanOverlap);
        $this->assertSame(['réserver'], $settings->ctaVocabulary);
        $this->assertSame(['nous contacter'], $settings->genericCta);
    }

    public function testHashesCoverTheManifestAndEveryPresentFile(): void
    {
        $this->write($this->validData());

        $hashes = CorpusManifest::load($this->dir)->hashes();

        $this->assertSame(sha1_file("{$this->dir}/manifest.json"), $hashes['manifest.json']);
        $this->assertSame(sha1("# Page\n"), $hashes['hard-01-page.md']);
    }

    public function testFromNameExplainsAMissingBenchDirectory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bench/ is not in the release zip');

        CorpusManifest::fromName($this->dir . '/nowhere', 'hard');
    }

    public function testInvalidJsonThrows(): void
    {
        file_put_contents("{$this->dir}/manifest.json", '{nope');

        $this->expectException(RuntimeException::class);

        CorpusManifest::load($this->dir);
    }
}
