<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

use IndianOpinion\PromptLibrary\Definition;
use IndianOpinion\PromptLibrary\Exception\PromptLibraryException;
use IndianOpinion\PromptLibrary\Exception\RequestException;

/**
 * Installs consumer-shipped defaults and records offers (cases 51 and 52).
 * Runs on admin_init and in WP-CLI, never on a front-end request. Nothing is
 * ever published automatically after version 1.
 */
class Defaults
{
    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly VersionRepository $versions,
        private readonly Consumers $consumers,
        private readonly Drafts $drafts,
        private readonly Publisher $publisher,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public function process(): void
    {
        foreach ($this->consumers->defaults() as $slug => $byConsumer) {
            foreach ($byConsumer as $consumer => $definition) {
                try {
                    $this->processOne((string) $slug, (string) $consumer, $definition);
                } catch (PromptLibraryException $e) {
                    $this->logger->throttled('default:' . $slug . ':' . $consumer, DAY_IN_SECONDS, 'error', 'A registered default template could not be used.', [
                        'slug' => $slug,
                        'consumer' => $consumer,
                        'code' => $e->errorCode(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /** @param array<mixed> $raw */
    private function processOne(string $slug, string $consumer, array $raw): void
    {
        $definition = Definition::fromArray(['slug' => $slug] + $raw, Drafts::limits());
        $hash = $definition->contentHash();
        $found = $this->templates->locate($slug);
        if ($found === null) {
            $id = $this->drafts->create($definition->toArray(), 0);
            $this->publisher->publishDefinition($id, $slug, $definition->toArray(), 'Installed by ' . $consumer, true, true, 0);
            $this->templates->setInstaller($id, $consumer);
            $this->templates->saveOffers($id, [$consumer => ['hash' => $hash, 'pending' => false, 'seen' => $this->clock->timestamp()]]);
            $this->versions->insertEvent($id, $slug, 'install', null, 1, 'Installed by ' . $consumer, 0, $this->clock->nowUtc());
            $this->logger->info('Default template installed.', ['slug' => $slug, 'consumer' => $consumer, 'template_id' => $id]);
            return;
        }
        $id = $found['id'];
        $installer = $this->templates->installer($id);
        if ($installer !== '' && $installer !== $consumer) {
            $this->logger->throttled('foreign-default:' . $slug . ':' . $consumer, DAY_IN_SECONDS, 'warning', 'A consumer registered a default for a template another consumer installed; it is kept as an offer only.', [
                'slug' => $slug,
                'consumer' => $consumer,
                'installer' => $installer,
            ]);
        }
        $offers = $this->templates->offers($id);
        $last = $offers[$consumer]['hash'] ?? null;
        if ($last === $hash) {
            return;
        }
        $liveHash = '';
        if ($found['live'] > 0) {
            $row = $this->versions->find($id, $found['live']);
            $liveHash = $row !== null ? (string) $row['content_hash'] : '';
        }
        $offers[$consumer] = ['hash' => $hash, 'pending' => $hash !== $liveHash, 'seen' => $this->clock->timestamp()];
        $this->templates->saveOffers($id, $offers);
        if ($hash !== $liveHash) {
            $this->logger->info('A consumer offers an update to a template.', ['slug' => $slug, 'consumer' => $consumer]);
        }
    }

    /**
     * Load a pending offer into the draft for review. Never publishes.
     */
    public function loadOffer(int $id, string $consumer, int $userId): void
    {
        $slug = $this->templates->slugOf($id);
        $definition = $this->consumers->defaultFrom($slug, $consumer);
        if ($definition === null) {
            throw new RequestException('promptlib_offer_missing', 'That consumer is not active, so its offer cannot be loaded.', 404);
        }
        $definition['slug'] = $slug;
        $definition['note'] = 'Update offered by ' . $consumer;
        $this->drafts->save($id, $definition, null, $userId);
        $offers = $this->templates->offers($id);
        if (isset($offers[$consumer])) {
            $offers[$consumer]['pending'] = false;
            $this->templates->saveOffers($id, $offers);
        }
    }
}
