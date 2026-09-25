<?php

namespace App\Modules\Catalog\Contracts;

/**
 * The future integration point for voice-assisted product creation
 * (docs/catalog.md §"Création vocale"). No implementation exists yet
 * and none is bound in CatalogServiceProvider — deliberately, until a
 * speech-to-text/AI provider is chosen (out of scope for this phase).
 *
 * When one is: a concrete class implements this, gets bound here
 * (`$this->app->bind(VoiceProductParser::class, ConcreteParser::class)`),
 * and a controller resolves it, validates the array it returns against
 * App\Modules\Catalog\Support\ProductRules::rules() and hands it to
 * ProductService — the exact same last two steps as manual creation and
 * Excel import. The AI never writes to the database directly.
 */
interface VoiceProductParser
{
    /**
     * @param  string  $transcript  already transcribed text (speech-to-text
     *                              itself is also out of scope here — this takes text in, not audio)
     * @return array<string, mixed> structured data shaped like a
     *                              ProductRules::rules() payload (name, category, purchase_price,
     *                              retail_enabled, retail_price, wholesale_enabled, wholesale_price,
     *                              ...) — NOT yet validated, NOT yet persisted.
     */
    public function parse(string $transcript): array;
}
