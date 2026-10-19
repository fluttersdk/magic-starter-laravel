<?php

namespace FlutterSdk\MagicStarter\Filament\Infolists;

use Filament\Infolists\Components\ViewEntry;
use FlutterSdk\MagicStarter\Filament\Support\CodeBlock;

/**
 * An infolist entry that shows its state as code: highlighted JSON for
 * structured state ({@see CodeBlock}), plain text otherwise, in a bordered
 * block with a language tag, a line count and a copy button.
 *
 * The block scrolls past [maxHeight] instead of growing the page, so a
 * prompt, a payload or a tool trace stays one screen however long it is. It
 * renders nothing for a blank state, so a caller usually pairs it with a
 * `visible()` on the same condition to drop the label as well.
 */
class CodeBlockEntry extends ViewEntry
{
    protected string $view = 'magic-starter::filament.code-block';

    /**
     * Rem units the block grows to before it scrolls.
     */
    protected int $maxHeight = 28;

    /**
     * Let the block grow to [$rem] rem before it scrolls: a table cell wants
     * a short block, a page body a tall one.
     */
    public function maxHeight(int $rem): static
    {
        $this->maxHeight = $rem;

        return $this;
    }

    public function getMaxHeight(): int
    {
        return $this->maxHeight;
    }

    public function getCodeBlock(): ?CodeBlock
    {
        return CodeBlock::from($this->getState());
    }
}
