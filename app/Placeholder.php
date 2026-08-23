<?php

declare(strict_types=1);

namespace TEB;

/**
 * A newspaper-styled placeholder for stories whose feed carries no picture.
 *
 * ⚠ THIS IS THE MOST-REPEATED GRAPHIC ON THE SITE, not an edge case. Of the
 * eighteen newsrooms on this roster only NASA licenses its photographs to us
 * (see docs/RECON.md — The Conversation cannot confirm image rights, Grist,
 * ProPublica, KFF and MIT all reserve theirs), and eight of the eighteen feeds
 * carry no image at all. So nearly every card on nearly every page is drawn
 * here. That makes this file part of the masthead, not a fallback.
 *
 * Drawn as SVG on our own domain: no download, no hotlink, scales to any card,
 * and it is cached hard because the same section always draws the same card.
 */
final class Placeholder
{
    /**
     * Ink on paper, per desk — read off this edition's own design tokens in
     * assets/css/site.css and nowhere else.
     *
     * The paper is --well (#EDEBE6) and the inks are a warm near-monochrome
     * ladder around --ink (#1C1917) and --accent (#7A1B26), because this
     * edition commits to one hue and a plumb line is an instrument, not a
     * paint chart. Every pair is at least 8.7:1, far past AA.
     *
     * ⚠ Keys are the desks THIS edition actually runs (TEB\Feeds::sections()).
     * A desk with no entry is not dropped on a default any more — unknownInk()
     * gives it a stable colour from the same ladder, so adding a desk to the
     * registry can never quietly paint it the same as politics.
     */
    private const PAPER = '#EDEBE6';

    private const PALETTE = [
        'politics'   => '#7A1B26',   // --accent, oxblood: the house desk
        'environment'=> '#2C3A33',   // slate green
        'education'  => '#43331F',   // umber
        'health'     => '#5A2733',   // dark rose
        'world'      => '#1C1917',   // --ink
        'business'   => '#3E2B23',   // brown-ink
        'technology' => '#2E2A3A',   // ink violet
        'science'    => '#24382F',   // deep moss
        'culture'    => '#5A3A1E',   // tan-ink
    ];

    /** The ladder an unregistered desk is drawn from, so none collide by accident. */
    private const LADDER = ['#1C1917', '#3E2B23', '#7A1B26', '#2C3A33', '#43331F', '#1F3348'];

    /**
     * A stable ink for a desk that is not in PALETTE.
     *
     * The old code returned one hardcoded default, which meant every desk the
     * registry gained after this file was written drew in the same colour as
     * politics — measured: three of ten desks on this edition, including two
     * that lead the front page. Hashing the name spreads them across the same
     * ladder instead, deterministically, so the answer never moves.
     */
    private static function unknownInk(string $section): string
    {
        if ($section === '') {
            return self::LADDER[0];
        }

        return self::LADDER[abs(crc32($section)) % count(self::LADDER)];
    }

    public static function isPlaceholder(string $url): bool
    {
        return strpos($url, 'placeholder.svg') !== false;
    }

    /** Route + query for a story's placeholder. */
    public static function url(array $a): string
    {
        $section = strtolower((string) ($a['section'] ?? ''));
        // Deterministic per story, so the same card always draws the same way
        // and the browser cache is never churned.
        $seed = abs(crc32((string) ($a['id'] ?? ($a['title'] ?? '')))) % 6;

        return Paths::url('/placeholder.svg') . (strpos(Paths::url('/placeholder.svg'), '?') === false ? '?' : '&')
            . 's=' . rawurlencode($section) . '&v=' . $seed;
    }

    /** The SVG itself. 1200x630 so it drops into any card box cleanly. */
    public static function svg(string $section, int $variant, array $cfg): string
    {
        $key   = strtolower($section);
        $ink   = self::PALETTE[$key] ?? self::unknownInk($key);
        $paper = self::PAPER;
        $variant = max(0, min(5, $variant));

        // The brand lives in config and nowhere else, including here.
        $name  = (string) ($cfg['site']['name'] ?? Config::get('site.name', ''));
        $label = strtoupper($section !== '' ? $section : (string) ($cfg['site']['short_name'] ?? ''));
        $e     = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');

        // Column rules, offset per variant so a grid of placeholders is not a
        // grid of identical tiles.
        $rules = '';
        $cols  = 3 + ($variant % 3);
        for ($i = 1; $i < $cols; $i++) {
            $x = (int) round(1200 * ($i / $cols));
            $rules .= '<line x1="' . $x . '" y1="300" x2="' . $x . '" y2="600" stroke="' . $ink . '" stroke-opacity=".16" stroke-width="2"/>';
        }

        // Text lines suggesting columns of type, varied by seed.
        $lines = '';
        for ($c = 0; $c < $cols; $c++) {
            $x0 = (int) round(1200 * ($c / $cols)) + 34;
            $w  = (int) round(1200 / $cols) - 68;
            for ($r = 0; $r < 7; $r++) {
                $y  = 330 + $r * 34;
                $ww = $r === 6 ? (int) round($w * (0.45 + 0.12 * (($variant + $c + $r) % 4))) : $w;
                $lines .= '<rect x="' . $x0 . '" y="' . $y . '" width="' . max(20, $ww) . '" height="10" rx="2" fill="' . $ink . '" fill-opacity=".13"/>';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 630" width="1200" height="630" role="img" aria-label="' . $e($name) . '">'
            . '<rect width="1200" height="630" fill="' . $paper . '"/>'
            . '<rect x="0" y="0" width="1200" height="8" fill="' . $ink . '"/>'
            . '<text x="600" y="150" text-anchor="middle" font-family="Georgia,&quot;Times New Roman&quot;,serif" font-size="76" font-weight="700" fill="' . $ink . '" letter-spacing="1">' . $e($name) . '</text>'
            . '<line x1="80" y1="196" x2="1120" y2="196" stroke="' . $ink . '" stroke-width="3"/>'
            . '<line x1="80" y1="206" x2="1120" y2="206" stroke="' . $ink . '" stroke-width="1"/>'
            . ($label !== ''
                ? '<text x="600" y="262" text-anchor="middle" font-family="Helvetica,Arial,sans-serif" font-size="30" font-weight="600" letter-spacing="8" fill="' . $ink . '" fill-opacity=".72">' . $e($label) . '</text>'
                : '')
            . $rules . $lines
            . '<line x1="80" y1="596" x2="1120" y2="596" stroke="' . $ink . '" stroke-width="3"/>'
            . '</svg>';
    }
}
