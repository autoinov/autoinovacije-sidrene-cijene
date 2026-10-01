<?php
/**
 * AI-sidrene cijene namjerno ne brišu regulatorne i audit podatke pri deinstalaciji.
 * Trajno brisanje dostupno je kao zasebna, izričito potvrđena administratorska radnja
 * u Alati > Sidrene cijene i cjenici, zajedno s prethodnim izvozom sigurnosne kopije.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
