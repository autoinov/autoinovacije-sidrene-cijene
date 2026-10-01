<?php

if (!defined('ABSPATH')) {
    exit;
}

final class AI_Sidrene_Cijene
{
    const OPCIJE = 'ai_sidrene_postavke';
    const VERZIJA_BAZE = 'ai_sidrene_verzija_baze';
    const PROMIJENJENO = 'ai_sidrene_promijenjeno';
    const CRON = 'ai_sidrene_dnevna_objava';
    const MIGRACIJA = 'ai_sidrene_migracija';
    const META_CIJENA = '_ai_sidrena_cijena';
    const META_DATUM = '_ai_sidreni_datum';
    const META_IZVOR = '_ai_sidreni_izvor';
    const META_ZAKLJUCANO = '_ai_sidrena_zakljucana';
    const META_PROVJERA = '_ai_sidrena_zadnja_provjera';
    const META_AUDIT = '_ai_sidrena_audit';
    const META_VRSTA = '_ai_sidrena_vrsta_stavke';
    const META_OBJAVA = '_ai_sidrena_javna_objava';
    const ADMIN_SLUG = 'ai-sidrene-cijene';
    const SHORTCODE = 'ai_sidrene_arhiva';
    const ARHIVA_SLUG = 'ai-cijenici';
    const REST_NS = 'ai-sidrene/v1';
    const BATCH = 200;
    private static $formatiranje = false;
    private static $admin_hook = '';

    public static function pokreni()
    {
        add_action('before_woocommerce_init', array(__CLASS__, 'deklariraj_kompatibilnost'));
        add_action('plugins_loaded', array(__CLASS__, 'nadogradi'));
        add_action('admin_notices', array(__CLASS__, 'obavijesti'));
        add_action('admin_menu', array(__CLASS__, 'admin_meni'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_stilovi'));
        add_action('admin_bar_menu', array(__CLASS__, 'admin_precac'), 80);
        add_action('rest_api_init', array(__CLASS__, 'rest_ruta'));
        add_action('init', array(__CLASS__, 'registriraj_cli'));
        add_shortcode(self::SHORTCODE, array(__CLASS__, 'arhiva_shortcode'));
        add_shortcode('sidrene_cijene_arhiva', array(__CLASS__, 'arhiva_shortcode'));
        add_action('wp_footer', array(__CLASS__, 'footer_link'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'javni_stilovi'));

        /* Mora se izvršiti nakon WooCommerce Lowest Price filtra (prioritet 1000). */
        add_filter('woocommerce_get_price_html', array(__CLASS__, 'filtriraj_cijenu_html'), 2000, 2);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'podaci_stavke'), 20, 2);
        add_action('woocommerce_product_options_pricing', array(__CLASS__, 'polja_proizvoda'));
        add_action('woocommerce_admin_process_product_object', array(__CLASS__, 'spremi_proizvod'));
        add_action('woocommerce_variation_options_pricing', array(__CLASS__, 'polja_varijacije'), 10, 3);
        add_action('woocommerce_save_product_variation', array(__CLASS__, 'spremi_varijaciju'), 10, 2);
        foreach (array('woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_delete_product', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status') as $hook) {
            add_action($hook, array(__CLASS__, 'oznaci_promjenu'), 30);
        }

        add_action('admin_post_ai_sidrene_objavi', array(__CLASS__, 'rucna_objava'));
        add_action('admin_post_ai_sidrene_spremi', array(__CLASS__, 'spremi_postavke'));
        add_action('admin_post_ai_sidrene_izvoz', array(__CLASS__, 'izvezi_sidrene_csv'));
        add_action('admin_post_ai_sidrene_izvoz_xlsx', array(__CLASS__, 'izvezi_sidrene_xlsx'));
        add_action('admin_post_ai_sidrene_uvoz', array(__CLASS__, 'uvezi_sidrene_datoteku'));
        add_action('admin_post_ai_sidrene_potvrdi_cijene', array(__CLASS__, 'potvrdi_trenutne_cijene'));
        add_action('admin_post_ai_sidrene_sigurnosna_kopija', array(__CLASS__, 'izvezi_sigurnosnu_kopiju'));
        add_action('admin_post_ai_sidrene_trajno_obrisi', array(__CLASS__, 'trajno_obrisi_podatke'));
        add_action(self::CRON, array(__CLASS__, 'cron_objava'));
        add_action(self::MIGRACIJA, array(__CLASS__, 'migriraj_batch'));
        add_filter('bulk_actions-edit-product', array(__CLASS__, 'bulk_radnje'));
        add_filter('handle_bulk_actions-edit-product', array(__CLASS__, 'obradi_bulk'), 10, 3);
        add_action('pre_get_posts', array(__CLASS__, 'filtriraj_proizvode_bez_sidrene'));
        add_filter('views_edit-product', array(__CLASS__, 'dodaj_filter_bez_sidrene'));
    }

    public static function aktiviraj()
    {
        if (get_option(self::OPCIJE, false) === false) {
            add_option(self::OPCIJE, self::zadano(), '', false);
        }
        self::napravi_spremiste();
        self::napravi_stranicu_arhive();
        self::rasporedi_cron();
        if (!wp_next_scheduled(self::MIGRACIJA)) {
            wp_schedule_single_event(time() + 30, self::MIGRACIJA);
        }
        update_option(self::PROMIJENJENO, '1', false);
    }

    public static function deaktiviraj()
    {
        wp_clear_scheduled_hook(self::CRON);
        wp_clear_scheduled_hook(self::MIGRACIJA);
    }

    public static function deklariraj_kompatibilnost()
    {
        if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', AI_SIDRENE_DATOTEKA, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', AI_SIDRENE_DATOTEKA, true);
        }
    }

    public static function nadogradi()
    {
        if (get_option(self::VERZIJA_BAZE) !== AI_SIDRENE_VERZIJA) {
            self::migriraj_opcije();
            self::napravi_spremiste();
            self::napravi_stranicu_arhive();
            self::rasporedi_cron();
            update_option(self::VERZIJA_BAZE, AI_SIDRENE_VERZIJA, false);
        }
    }

    private static function migriraj_opcije()
    {
        $stare = get_option('ai_postavke', array());
        $nove = get_option(self::OPCIJE, array());
        $mapa = array('business_name' => 'naziv','business_address' => 'adresa','object_type' => 'vrsta_objekta','object_code' => 'oznaka_objekta','default_reference_date' => 'zadani_datum','auto_publish' => 'auto','footer_link' => 'footer','archive_days' => 'retencija');
        foreach ($mapa as $stari => $novi) {
            if (!isset($nove[$novi]) && isset($stare[$stari])) {
                $nove[$novi] = $stare[$stari];
            }
        }
        update_option(self::OPCIJE, array_merge(self::zadano(), $nove), false);
    }

    public static function migriraj_batch()
    {
        global $wpdb;
        if (!($wpdb instanceof wpdb)) {
            return;
        }
        $zadnji = (int) get_option('ai_sidrene_migracija_kursor', 0);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time batched migration; caching would retain stale migration state.
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ('product','product_variation') ORDER BY ID ASC LIMIT %d", $zadnji, self::BATCH));
        foreach ($ids as $id) {
            if (get_post_meta($id, self::META_CIJENA, true) !== '') {
                add_post_meta($id, self::META_ZAKLJUCANO, '1', true);
            }
            $zadnji = (int) $id;
        }
        update_option('ai_sidrene_migracija_kursor', $zadnji, false);
        if (count($ids) === self::BATCH) {
            wp_schedule_single_event(time() + 10, self::MIGRACIJA);
        } else {
            update_option('ai_sidrene_migracija_zavrsena', current_time('mysql'), false);
        }
    }

    private static function zadano()
    {
        return array('naziv' => get_bloginfo('name'),'adresa' => '','vrsta_objekta' => 'Web trgovina','oznaka_objekta' => '01','zadani_datum' => '10.09.2026.','auto' => '1','footer' => '1','katalog' => '1','ukljuci_skrivene' => '0','retencija' => '31','brand_izvor' => 'taxonomy:product_brand','ean_meta' => '_global_unique_id','debug' => '0');
    }

    private static function postavke()
    {
        $p = get_option(self::OPCIJE, array());
        return array_merge(self::zadano(), is_array($p) ? $p : array());
    }

    private static function ovlasten()
    {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    public static function obavijesti()
    {
        if (!class_exists('WooCommerce') && current_user_can('activate_plugins')) {
            echo '<div class="notice notice-error"><p><strong>Autoinovacije – Sidrene cijene i cjenici:</strong> potreban je aktivan WooCommerce.</p></div>';
            return;
        }
        if (!self::ovlasten() || get_option(self::PROMIJENJENO, '1') !== '1') {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->id === self::$admin_hook) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>AI cjenik čeka objavu.</strong> <a class="button" href="' . esc_url(self::url_radnje('ai_sidrene_objavi')) . '">Objavi sada</a></p></div>';
    }

    public static function admin_meni()
    {
        self::$admin_hook = add_management_page('Autoinovacije – Sidrene cijene i cjenici', 'Sidrene cijene i cjenici', 'manage_options', self::ADMIN_SLUG, array(__CLASS__,'admin_stranica'));
    }

    public static function admin_stilovi($hook)
    {
        if ($hook !== self::$admin_hook) {
            return;
        }
        wp_enqueue_style('ai-sidrene-admin', plugin_dir_url(AI_SIDRENE_DATOTEKA) . 'assets/css/admin.css', array(), AI_SIDRENE_VERZIJA);
    }

    public static function admin_precac($bar)
    {
        if (!self::ovlasten() || !is_admin_bar_showing()) {
            return;
        }
        $dirty = get_option(self::PROMIJENJENO, '1') === '1';
        $bar->add_node(array('id' => 'ai-sidrene','title' => $dirty ? 'Objavi AI cjenik' : 'AI cjenik je ažuran','href' => $dirty ? self::url_radnje('ai_sidrene_objavi') : admin_url('tools.php?page=' . self::ADMIN_SLUG)));
    }

    private static function datum($vrijednost)
    {
        $vrijednost = trim((string) $vrijednost);
        $d = DateTimeImmutable::createFromFormat('!d.m.Y.', $vrijednost);
        return $d && $d->format('d.m.Y.') === $vrijednost ? $vrijednost : '';
    }

    public static function polja_proizvoda()
    {
        $id = get_the_ID();
        echo '<div class="options_group">';
        woocommerce_wp_text_input(array('id' => self::META_CIJENA,'label' => 'Sidrena cijena (' . get_woocommerce_currency_symbol() . ')','data_type' => 'price','desc_tip' => true,'description' => 'Povijesna cijena na referentni datum; ne mijenja se s aktualnom cijenom.'));
        woocommerce_wp_text_input(array('id' => self::META_DATUM,'label' => 'Datum sidrene cijene','placeholder' => '10.09.2026.'));
        woocommerce_wp_select(array('id' => self::META_IZVOR,'label' => 'Izvor podatka','options' => self::izvori(),'value' => get_post_meta($id, self::META_IZVOR, true)));
        woocommerce_wp_select(array('id' => self::META_VRSTA,'label' => 'Vrsta stavke u cjeniku','options' => self::vrste_stavke(),'value' => get_post_meta($id, self::META_VRSTA, true),'desc_tip' => true,'description' => 'Automatski: virtualni WooCommerce proizvod smatra se uslugom; ostali se smatraju proizvodima.'));
        woocommerce_wp_select(array('id' => self::META_OBJAVA,'label' => 'Javna objava','options' => self::nacini_objave(),'value' => get_post_meta($id, self::META_OBJAVA, true),'desc_tip' => true,'description' => 'Lozinkom zaštićene stavke uvijek su isključene.'));
        woocommerce_wp_checkbox(array('id' => self::META_ZAKLJUCANO,'label' => 'Zaključaj sidrenu cijenu','description' => 'Zaštita od nenamjernih automatiziranih promjena.'));
        echo '</div>';
    }

    private static function izvori()
    {
        return array('' => '— odaberite —','historical_database' => 'Povijesna baza','existing_plugin' => 'Postojeći dodatak','ERP_import' => 'ERP uvoz','CSV_import' => 'CSV / Excel uvoz','backup_restore' => 'Obnova sigurnosne kopije','manual' => 'Ručni unos','manual_confirmation' => 'Administratorska potvrda cijene','migration' => 'Migracija');
    }

    private static function vrste_stavke()
    {
        return array('' => 'Automatski prema WooCommerce postavci','proizvod' => 'Proizvod','usluga' => 'Usluga');
    }

    private static function nacini_objave()
    {
        return array('' => 'Automatski prema vidljivosti kataloga','ukljuci' => 'Uvijek uključi','iskljuci' => 'Isključi iz javnog cjenika');
    }

    public static function polja_varijacije($loop, $data, $variation)
    {
        $id = (int)$variation->ID;
        woocommerce_wp_text_input(array('id' => 'ai_cijena_' . $id,'name' => 'ai_cijena[' . $id . ']','value' => get_post_meta($id, self::META_CIJENA, true),'label' => 'Sidrena cijena','data_type' => 'price','wrapper_class' => 'form-row form-row-first'));
        woocommerce_wp_text_input(array('id' => 'ai_datum_' . $id,'name' => 'ai_datum[' . $id . ']','value' => get_post_meta($id, self::META_DATUM, true),'label' => 'Referentni datum','wrapper_class' => 'form-row form-row-last'));
        woocommerce_wp_select(array('id' => 'ai_izvor_' . $id,'name' => 'ai_izvor[' . $id . ']','value' => get_post_meta($id, self::META_IZVOR, true),'label' => 'Izvor','options' => self::izvori(),'wrapper_class' => 'form-row form-row-first'));
        woocommerce_wp_select(array('id' => 'ai_vrsta_' . $id,'name' => 'ai_vrsta[' . $id . ']','value' => get_post_meta($id, self::META_VRSTA, true),'label' => 'Vrsta stavke','options' => self::vrste_stavke(),'wrapper_class' => 'form-row form-row-last'));
        woocommerce_wp_select(array('id' => 'ai_objava_' . $id,'name' => 'ai_objava[' . $id . ']','value' => get_post_meta($id, self::META_OBJAVA, true),'label' => 'Javna objava','options' => self::nacini_objave(),'wrapper_class' => 'form-row form-row-first'));
        woocommerce_wp_checkbox(array('id' => 'ai_lock_' . $id,'name' => 'ai_lock[' . $id . ']','value' => get_post_meta($id, self::META_ZAKLJUCANO, true),'label' => 'Zaključano','wrapper_class' => 'form-row form-row-last'));
    }

    public static function spremi_proizvod($proizvod)
    {
        if (empty($_POST['woocommerce_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['woocommerce_meta_nonce'])), 'woocommerce_save_data')) {
            return;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce price is unslashed and sanitized immediately with wc_clean().
        $cijena = isset($_POST[self::META_CIJENA]) ? wc_clean(wp_unslash($_POST[self::META_CIJENA])) : null;
        $datum = isset($_POST[self::META_DATUM]) ? sanitize_text_field(wp_unslash($_POST[self::META_DATUM])) : null;
        $izvor = isset($_POST[self::META_IZVOR]) ? sanitize_key(wp_unslash($_POST[self::META_IZVOR])) : null;
        $vrsta = isset($_POST[self::META_VRSTA]) ? sanitize_key(wp_unslash($_POST[self::META_VRSTA])) : '';
        $objava = isset($_POST[self::META_OBJAVA]) ? sanitize_key(wp_unslash($_POST[self::META_OBJAVA])) : '';
        self::spremi_sidrenu($proizvod, array('cijena' => $cijena,'datum' => $datum,'izvor' => $izvor,'lock' => isset($_POST[self::META_ZAKLJUCANO]) ? '1' : '0','vrsta' => $vrsta,'objava' => $objava));
    }

    public static function spremi_varijaciju($id, $index)
    {
        if (empty($_POST['woocommerce_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['woocommerce_meta_nonce'])), 'woocommerce_save_data')) {
            return;
        }
        $p = wc_get_product($id);
        if (!$p) {
            return;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Variation price is unslashed and sanitized immediately with wc_clean().
        $cijena = isset($_POST['ai_cijena'][$id]) ? wc_clean(wp_unslash($_POST['ai_cijena'][$id])) : null;
        $datum = isset($_POST['ai_datum'][$id]) ? sanitize_text_field(wp_unslash($_POST['ai_datum'][$id])) : null;
        $izvor = isset($_POST['ai_izvor'][$id]) ? sanitize_key(wp_unslash($_POST['ai_izvor'][$id])) : null;
        $vrsta = isset($_POST['ai_vrsta'][$id]) ? sanitize_key(wp_unslash($_POST['ai_vrsta'][$id])) : '';
        $objava = isset($_POST['ai_objava'][$id]) ? sanitize_key(wp_unslash($_POST['ai_objava'][$id])) : '';
        self::spremi_sidrenu($p, array('cijena' => $cijena,'datum' => $datum,'izvor' => $izvor,'lock' => isset($_POST['ai_lock'][$id]) ? '1' : '0','vrsta' => $vrsta,'objava' => $objava));
        $p->save();
    }

    private static function spremi_sidrenu($p, $ulaz)
    {
        if (!self::ovlasten()) {
            return;
        }
        $staro = array('cijena' => $p->get_meta(self::META_CIJENA, true),'datum' => $p->get_meta(self::META_DATUM, true),'izvor' => $p->get_meta(self::META_IZVOR, true),'lock' => $p->get_meta(self::META_ZAKLJUCANO, true),'vrsta' => $p->get_meta(self::META_VRSTA, true),'objava' => $p->get_meta(self::META_OBJAVA, true));
        $cijena = $ulaz['cijena'] === null ? null : wc_format_decimal(wc_clean(wp_unslash($ulaz['cijena'])));
        $datum = $ulaz['datum'] === null ? null : self::datum(sanitize_text_field(wp_unslash($ulaz['datum'])));
        $izvor = $ulaz['izvor'] === null ? null : sanitize_key(wp_unslash($ulaz['izvor']));
        if ($cijena !== null && ($cijena === '' || (is_numeric($cijena) && (float)$cijena >= 0))) {
            $cijena === '' ? $p->delete_meta_data(self::META_CIJENA) : $p->update_meta_data(self::META_CIJENA, $cijena);
        }
        if ($datum !== null) {
            $datum === '' ? $p->delete_meta_data(self::META_DATUM) : $p->update_meta_data(self::META_DATUM, $datum);
        }
        if ($izvor !== null && array_key_exists($izvor, self::izvori())) {
            $p->update_meta_data(self::META_IZVOR, $izvor);
        }
        $p->update_meta_data(self::META_ZAKLJUCANO, $ulaz['lock'] === '1' ? '1' : '0');
        $vrsta = array_key_exists('vrsta', $ulaz) ? sanitize_key($ulaz['vrsta']) : (string)$p->get_meta(self::META_VRSTA, true);
        $objava = array_key_exists('objava', $ulaz) ? sanitize_key($ulaz['objava']) : (string)$p->get_meta(self::META_OBJAVA, true);
        if (array_key_exists('vrsta', $ulaz)) {
            in_array($vrsta, array('proizvod','usluga'), true) ? $p->update_meta_data(self::META_VRSTA, $vrsta) : $p->delete_meta_data(self::META_VRSTA);
        }
        if (array_key_exists('objava', $ulaz)) {
            in_array($objava, array('ukljuci','iskljuci'), true) ? $p->update_meta_data(self::META_OBJAVA, $objava) : $p->delete_meta_data(self::META_OBJAVA);
        }
        $p->update_meta_data(self::META_PROVJERA, current_time('mysql'));
        $novo = array('cijena' => $cijena,'datum' => $datum,'izvor' => $izvor,'lock' => $ulaz['lock'],'vrsta' => $vrsta,'objava' => $objava);
        if ($staro != $novo) {
            $audit = $p->get_meta(self::META_AUDIT, true);
            $audit = is_array($audit) ? $audit : array();
            $audit[] = array('vrijeme' => current_time('mysql'),'korisnik' => get_current_user_id(),'staro' => $staro,'novo' => $novo);
            $p->update_meta_data(self::META_AUDIT, array_slice($audit, -20));
        }
    }

    private static function sidrena($p)
    {
        if (!$p) {
            return array('','','',false);
        }
        $c = $p->get_meta(self::META_CIJENA, true);
        $d = $p->get_meta(self::META_DATUM, true);
        $i = $p->get_meta(self::META_IZVOR, true);
        if ($p->is_type('variation') && $c === '') {
            $parent = wc_get_product($p->get_parent_id());
            if ($parent) {
                $c = $parent->get_meta(self::META_CIJENA, true);
                $d = $parent->get_meta(self::META_DATUM, true);
                $i = $parent->get_meta(self::META_IZVOR, true);
            }
        }
        if ($c === '' || !is_numeric($c) || (float)$c < 0 || self::datum($d) === '') {
            return array('','','',false);
        }
        return array(wc_format_decimal($c),$d,$i,true);
    }

    public static function filtriraj_cijenu_html($html, $p)
    {
        if (self::$formatiranje || !$p || (is_admin() && !wp_doing_ajax()) || is_cart() || is_checkout()) {
            return $html;
        }
        if (!is_product() && self::postavke()['katalog'] !== '1') {
            return $html;
        }
        if (strpos($html, 'ai-sidrena') !== false || !apply_filters('ai_sidrene_prikazi', true, $p)) {
            return $html;
        }
        $dodatak = self::sidrena_html($p);
        return $dodatak === '' ? $html : $html . $dodatak;
    }

    private static function sidrena_html($p)
    {
        if ($p->is_type('variable')) {
            $c = array();
            $d = array();
            foreach ($p->get_children() as $id) {
                list($vc,$vd,,$ok) = self::sidrena(wc_get_product($id));
                if ($ok) {
                    $c[] = (float)$vc;
                    $d[] = $vd;
                }
            }if (!$c) {
                return '';
            }self::$formatiranje = true;
            $v = min($c) === max($c) ? wc_price(min($c)) : wc_format_price_range(min($c), max($c));
            self::$formatiranje = false;
            $d = array_unique($d);
            $datum = count($d) === 1 ? reset($d) : __('ovisno o varijaciji', 'autoinovacije-sidrene-cijene');
        } else {
            list($c,$datum,,$ok) = self::sidrena($p);
            if (!$ok) {
                return '';
            }self::$formatiranje = true;
            $v = wc_price((float)$c);
            self::$formatiranje = false;
        }
        /* translators: %s: reference date for the anchor price. */
        $label = apply_filters('ai_sidrene_oznaka', __('Cijena na dan %s:', 'autoinovacije-sidrene-cijene'), $p);
        return '<span class="ai-sidrena"><span>' . esc_html(sprintf($label, $datum)) . ' ' . wp_kses_post($v) . '</span><span class="ai-info" tabindex="0" aria-label="' . esc_attr__('Objašnjenje sidrene cijene', 'autoinovacije-sidrene-cijene') . '"></span><small class="ai-tooltip" role="tooltip">' . esc_html__('Povijesna referentna cijena. Nije aktualna, akcijska ni najniža cijena u prethodnih 30 dana.', 'autoinovacije-sidrene-cijene') . '</small></span>';
    }

    public static function podaci_stavke($podaci, $stavka)
    {
        if (empty($stavka['data'])) {
            return $podaci;
        } list($c,$d,,$ok) = self::sidrena($stavka['data']);
        if (!$ok) {
            return $podaci;
        }
        self::$formatiranje = true;
        $v = wc_price((float)$c);
        self::$formatiranje = false;
        /* translators: %s: reference date for the anchor price. */
        $podaci[] = array('key' => sprintf(__('Cijena na dan %s', 'autoinovacije-sidrene-cijene'), $d),'value' => wp_kses_post($v),'display' => wp_kses_post($v));
        return $podaci;
    }

    public static function javni_stilovi()
    {
        if (is_admin()) {
            return;
        }
        wp_enqueue_style('ai-sidrene', plugin_dir_url(AI_SIDRENE_DATOTEKA) . 'assets/css/public.css', array(), AI_SIDRENE_VERZIJA);
    }

    public static function oznaci_promjenu($id = 0)
    {
        if (get_transient('ai_sidrene_generiranje')) {
            return;
        }update_option(self::PROMIJENJENO, '1', false);
        update_option('ai_sidrene_zadnja_promjena', time(), false);
    }

    private static function spremiste()
    {
        $u = wp_upload_dir();
        $dir = trailingslashit($u['basedir']) . 'ai-sidrene-cijene';
        $url = trailingslashit($u['baseurl']) . 'ai-sidrene-cijene';
        return array(
            'dir' => $dir,
            'url' => $url,
            'xml' => $dir . '/cjenik.xml',
            'csv' => $dir . '/cjenik.csv',
            'xml_url' => $url . '/cjenik.xml',
            'csv_url' => $url . '/cjenik.csv',
            'proizvodi_xml' => $dir . '/cjenik-proizvodi.xml',
            'proizvodi_csv' => $dir . '/cjenik-proizvodi.csv',
            'proizvodi_xml_url' => $url . '/cjenik-proizvodi.xml',
            'proizvodi_csv_url' => $url . '/cjenik-proizvodi.csv',
            'usluge_xml' => $dir . '/cjenik-usluge.xml',
            'usluge_csv' => $dir . '/cjenik-usluge.csv',
            'usluge_xml_url' => $url . '/cjenik-usluge.xml',
            'usluge_csv_url' => $url . '/cjenik-usluge.csv',
        );
    }
    private static function napravi_spremiste()
    {
        $s = self::spremiste();
        if (!is_dir($s['dir']) && !wp_mkdir_p($s['dir'])) {
            return false;
        }if (!file_exists($s['dir'] . '/index.php')) {
            file_put_contents($s['dir'] . '/index.php', "<?php\n// Zabranjen izravan pristup.\n", LOCK_EX);
        }return wp_is_writable($s['dir']);
    }

    private static function napravi_stranicu_arhive()
    {
        $id = (int)get_option('ai_sidrene_stranica', 0);
        $p = $id ? get_post($id) : null;
        if (!$p || $p->post_type !== 'page') {
            $p = get_page_by_path(self::ARHIVA_SLUG);
        }
        if ($p) {
            $id = $p->ID;
            $shortcode = '[' . self::SHORTCODE . ']';
            $sadrzaj = str_replace(
                array('[sidrene_cijene_arhiva]'),
                $shortcode,
                (string)$p->post_content
            );
            if (strpos($sadrzaj, $shortcode) === false) {
                $sadrzaj = trim($sadrzaj . "\n\n" . $shortcode);
            }
            $dijelovi = explode($shortcode, $sadrzaj);
            if (count($dijelovi) > 2) {
                $sadrzaj = array_shift($dijelovi) . $shortcode . implode('', $dijelovi);
            }
            $sadrzaj = trim((string)preg_replace('/\n{3,}/', "\n\n", $sadrzaj));
            if ($sadrzaj !== trim((string)$p->post_content) || $p->post_name !== self::ARHIVA_SLUG) {
                wp_update_post(
                    array(
                        'ID' => $id,
                        'post_name' => self::ARHIVA_SLUG,
                        'post_content' => $sadrzaj,
                    )
                );
            }
            delete_post_meta($id, '_wp_old_slug', 'cjenici');
        } else {
            $id = wp_insert_post(
                array(
                    'post_title' => 'Cjenici',
                    'post_name' => self::ARHIVA_SLUG,
                    'post_content' => '[' . self::SHORTCODE . ']',
                    'post_status' => 'publish',
                    'post_type' => 'page',
                ),
                true
            );
        }
        if (!is_wp_error($id)) {
            update_option('ai_sidrene_stranica', (int)$id, false);
        }
    }

    private static function sljedeci_cron()
    {
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $n = new DateTimeImmutable('now', $tz);
        $x = $n->setTime(7, 30);
        if ($x <= $n) {
            $x = $x->modify('+1 day');
        }return $x->getTimestamp();
    }
    private static function rasporedi_cron()
    {
        $on = self::postavke()['auto'] === '1';
        if (!$on) {
            wp_clear_scheduled_hook(self::CRON);
        } elseif (!wp_next_scheduled(self::CRON)) {
            wp_schedule_single_event(self::sljedeci_cron(), self::CRON);
        }
    }
    public static function cron_objava()
    {
        if (self::postavke()['auto'] === '1') {
            self::generiraj_cjenike('cron');
        }self::rasporedi_cron();
    }

    private static function vidljiva_cijena($p, $raw = null)
    {
        $raw = $raw === null ? $p->get_price() : $raw;
        if ($raw === '') {
            return '';
        }return wc_format_decimal(wc_get_price_to_display($p, array('price' => (float)$raw)), wc_get_price_decimals());
    }
    private static function brand($p)
    {
        $izvor = self::postavke()['brand_izvor'];
        if (strpos($izvor, 'taxonomy:') === 0) {
            $t = substr($izvor, 9);
            $terms = get_the_terms($p->get_id(), $t);
            if ($terms && !is_wp_error($terms)) {
                return implode(', ', wp_list_pluck($terms, 'name'));
            }
        }if (strpos($izvor, 'meta:') === 0) {
            return (string)$p->get_meta(substr($izvor, 5), true);
        }return '';
    }
    private static function ean($p)
    {
        $key = self::postavke()['ean_meta'];
        return $key ? (string)$p->get_meta($key, true) : '';
    }
    private static function jedinica($p)
    {
        foreach (array('_unit','unit','_measurement_unit') as $k) {
            $v = $p->get_meta($k, true);
            if ($v !== '') {
                return (string)$v;
            }
        }return '';
    }
    private static function cijena_jedinice($p)
    {
        foreach (array('_unit_price','unit_price','_measurement_price') as $k) {
            $v = $p->get_meta($k, true);
            if ($v !== '' && is_numeric($v)) {
                return self::vidljiva_cijena($p, $v);
            }
        }return '';
    }

    private static function naslijedena_meta($p, $kljuc)
    {
        $vrijednost = (string)$p->get_meta($kljuc, true);
        if ($vrijednost === '' && $p->is_type('variation')) {
            $roditelj = wc_get_product($p->get_parent_id());
            if ($roditelj) {
                $vrijednost = (string)$roditelj->get_meta($kljuc, true);
            }
        }
        return $vrijednost;
    }

    private static function vrsta_stavke($p)
    {
        $vrsta = self::naslijedena_meta($p, self::META_VRSTA);
        if (in_array($vrsta, array('proizvod','usluga'), true)) {
            return $vrsta;
        }
        return $p->is_virtual() ? 'usluga' : 'proizvod';
    }

    private static function smije_u_javni_cjenik($p)
    {
        if (!$p || $p->get_status() !== 'publish') {
            return false;
        }
        $roditelj = $p->is_type('variation') ? wc_get_product($p->get_parent_id()) : null;
        if ((string)get_post_field('post_password', $p->get_id()) !== '' || ($roditelj && (string)get_post_field('post_password', $roditelj->get_id()) !== '')) {
            return false;
        }
        $objava = self::naslijedena_meta($p, self::META_OBJAVA);
        if ($objava === 'iskljuci') {
            return false;
        }
        if ($objava === 'ukljuci') {
            return true;
        }
        $vidljivost = $roditelj ? $roditelj->get_catalog_visibility() : $p->get_catalog_visibility();
        return $vidljivost !== 'hidden' || self::postavke()['ukljuci_skrivene'] === '1';
    }

    private static function export_zapis($p)
    {
        list($a,$d,$izvor,$ok) = self::sidrena($p);
        $parent = $p->is_type('variation') ? $p->get_parent_id() : 0;
        $najmanja = $p->get_meta('_lowest_price_30_days', true);
        $zapis = array(
            'vrsta_stavke' => self::vrsta_stavke($p),'product_id' => $parent ?: $p->get_id(),'variation_id' => $parent ? $p->get_id() : 0,'sku' => $p->get_sku(),'ean' => self::ean($p),'naziv' => wp_strip_all_tags($p->get_name()),'brand' => self::brand($p),
            'jedinica_mjere' => self::jedinica($p),'cijena_za_jedinicu_mjere' => self::cijena_jedinice($p),'valuta' => get_woocommerce_currency(),'maloprodajna_cijena' => self::vidljiva_cijena($p),'redovna_cijena' => self::vidljiva_cijena($p, $p->get_regular_price()),
            'naj_niza_cijena_30_dana' => $najmanja !== '' ? self::vidljiva_cijena($p, $najmanja) : '','poseban_oblik_prodaje' => $p->is_on_sale() ? 'da' : 'ne','naziv_posebnog_oblika_prodaje' => $p->is_on_sale() ? 'Akcija' : '',
            'sidrena_cijena' => $ok ? $a : '','sidreni_datum' => $ok ? $d : '','sidreni_izvor' => $ok ? $izvor : '','barkod' => self::ean($p),'dostupnost' => $p->is_in_stock() ? 'dostupno' : 'nedostupno','poveznica' => $p->get_permalink(),'zadnja_promjena' => $p->get_date_modified() ? $p->get_date_modified()->date(DATE_ATOM) : ''
        );
        return apply_filters('ai_sidrene_feed_zapis', $zapis, $p);
    }

    private static function zaglavlje_feeda()
    {
        return array('vrsta_stavke','product_id','variation_id','sku','ean','naziv','brand','jedinica_mjere','cijena_za_jedinicu_mjere','valuta','maloprodajna_cijena','redovna_cijena','naj_niza_cijena_30_dana','poseban_oblik_prodaje','naziv_posebnog_oblika_prodaje','sidrena_cijena','sidreni_datum','sidreni_izvor','barkod','dostupnost','poveznica','zadnja_promjena');
    }

    private static function export_ids($stranica)
    {
        return wc_get_products(array('status' => 'publish','limit' => self::BATCH,'page' => $stranica,'paginate' => true,'type' => array('simple','external','variable','grouped'),'orderby' => 'ID','order' => 'ASC','return' => 'ids'));
    }
    private static function izvozne_stavke($id)
    {
        $p = wc_get_product($id);
        if (!$p) {
            return array();
        }if ($p->is_type('variable')) {
            $r = array();
            foreach ($p->get_children() as $v) {
                $vp = wc_get_product($v);
                if ($vp && $vp->get_status() === 'publish') {
                    $r[] = $vp;
                }
            }return $r;
        }return array($p);
    }
    private static function csv_celija($v)
    {
        $v = (string)$v;
        return preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
    }

    public static function generiraj_cjenike($razlog = 'manual')
    {
        if (!function_exists('wc_get_products') || !self::napravi_spremiste()) {
            return new WP_Error('ai_spremiste', 'Spremište nije dostupno.');
        }
        $postavke = self::postavke();
        foreach (array('naziv','adresa','vrsta_objekta','oznaka_objekta') as $obvezno) {
            if (trim((string) $postavke[$obvezno]) === '') {
                $poruka = 'Objava nije pokrenuta: unesite naziv, adresu, vrstu i oznaku objekta.';
                update_option('ai_sidrene_zadnja_greska', $poruka, false);
                return new WP_Error('ai_postavke', $poruka);
            }
        }
        $s = self::spremiste();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- An exclusive stream lock prevents overlapping cron and manual exports.
        $lock = fopen($s['dir'] . '/.generate.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return new WP_Error('ai_lock', 'Generiranje je već u tijeku.');
        }set_transient('ai_sidrene_generiranje', '1', 30 * MINUTE_IN_SECONDS);
        $start = microtime(true);
        $stamp = current_time('Ymd\THis');
        $tmpCsv = $s['dir'] . '/.' . $stamp . '.csv.tmp';
        $tmpXml = $s['dir'] . '/.' . $stamp . '.xml.tmp';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The catalogue is streamed to keep memory usage bounded on large stores.
        $csv = fopen($tmpCsv, 'wb');
        $xml = new XMLWriter();
        $ok = $csv && $xml->openURI($tmpXml);
        if (!$ok) {
            self::otkljucaj($lock);
            return new WP_Error('ai_open', 'Privremene datoteke nije moguće otvoriti.');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes the UTF-8 BOM to the already validated private export stream.
        fwrite($csv, "\xEF\xBB\xBF");
        fputcsv($csv, self::zaglavlje_feeda(), ';');
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('cjenik');
        $xml->writeAttribute('generirano', current_time(DATE_ATOM));
        $xml->startElement('objekt');
        foreach (array('naziv','adresa','vrsta_objekta','oznaka_objekta') as $k) {
            $xml->writeElement($k, $postavke[$k]);
        }$xml->endElement();
        $xml->startElement('proizvodi');
        $count = 0;
        $warnings = 0;
        $page = 1;
        do {
            $result = self::export_ids($page);
            foreach ($result->products as $id) {
                foreach (self::izvozne_stavke($id) as $p) {
                    if (!self::smije_u_javni_cjenik($p)) {
                        continue;
                    }
                    try {
                            $row = self::export_zapis($p);
                            fputcsv($csv, array_map(array(__CLASS__,'csv_celija'), array_values($row)), ';');
                            $xml->startElement('proizvod');
                        foreach ($row as $k => $v) {
                            $xml->writeElement($k, (string)$v);
                        }$xml->endElement();
                            $count++;
                    } catch (Throwable $e) {
                                $warnings++;
                                self::log('error', 'feed', $e->getMessage(), array('product_id' => $id));
                    }
                }
            }$page++;
        } while ($page <= $result->max_num_pages);
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the streamed export opened above.
        fclose($csv);
        $validCsv = is_file($tmpCsv) && filesize($tmpCsv) > 3;
        $dom = new DOMDocument();
        $validXml = is_file($tmpXml) && @$dom->load($tmpXml);
        if (!$validCsv || !$validXml) {
            wp_delete_file($tmpCsv);
            wp_delete_file($tmpXml);
            update_option('ai_sidrene_zadnja_greska', 'Validacija izlaznih datoteka nije uspjela.', false);
            self::otkljucaj($lock);
            return new WP_Error('ai_validation', 'Validacija feeda nije uspjela; zadnji valjani feed je sačuvan.');
        }
        $broj = (int)get_option('ai_sidrene_broj_objave', 0) + 1;
        $base = sanitize_title($postavke['vrsta_objekta'] . '-' . $postavke['adresa'] . '-' . $postavke['oznaka_objekta']);
        $archiveCsv = $s['dir'] . '/cjenik_' . $base . '_' . str_pad($broj, 6, '0', STR_PAD_LEFT) . '_' . $stamp . '.csv';
        $archiveXml = $s['dir'] . '/cjenik_' . $base . '_' . str_pad($broj, 6, '0', STR_PAD_LEFT) . '_' . $stamp . '.xml';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic move preserves the last valid feed if publishing is interrupted.
        rename($tmpCsv, $archiveCsv);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic move preserves the last valid feed if publishing is interrupted.
        rename($tmpXml, $archiveXml);
        $a = self::zamijeni_aktualni($archiveCsv, $s['csv']);
        $b = self::zamijeni_aktualni($archiveXml, $s['xml']);
        if (!$a || !$b) {
            self::otkljucaj($lock);
            return new WP_Error('ai_publish', 'Arhiva je spremljena, ali aktualni feed nije zamijenjen.');
        }
        foreach (array('proizvod' => 'proizvodi', 'usluga' => 'usluge') as $vrsta => $oznaka) {
            $izdvojeno = self::izradi_izdvojeni_cjenik($archiveCsv, $vrsta, $postavke, $base, $broj, $stamp);
            if (is_wp_error($izdvojeno)) {
                $warnings++;
                self::log('error', 'feed', $izdvojeno->get_error_message(), array('vrsta' => $vrsta));
                continue;
            }
            if (!self::zamijeni_aktualni($izdvojeno['csv'], $s[$oznaka . '_csv']) || !self::zamijeni_aktualni($izdvojeno['xml'], $s[$oznaka . '_xml'])) {
                $warnings++;
                self::log('error', 'feed', 'Izdvojeni aktualni cjenik nije moguće zamijeniti.', array('vrsta' => $vrsta));
            }
        }
        update_option('ai_sidrene_broj_objave', $broj, false);
        update_option('ai_sidrene_zadnja_objava', time(), false);
        update_option('ai_sidrene_trajanje', round(microtime(true) - $start, 3), false);
        update_option('ai_sidrene_broj_zapisa', $count, false);
        update_option('ai_sidrene_upozorenja', $warnings, false);
        update_option('ai_sidrene_zadnja_greska', '', false);
        update_option(self::PROMIJENJENO, '0', false);
        self::ocisti_arhivu();
        self::otkljucaj($lock);
        do_action('ai_sidrene_nakon_objave', $count, $razlog);
        return array('zapisi' => $count,'upozorenja' => $warnings);
    }

    private static function izradi_izdvojeni_cjenik($izvorni_csv, $vrsta, $postavke, $base, $broj, $stamp)
    {
        $s = self::spremiste();
        $oznaka = $vrsta === 'usluga' ? 'usluge' : 'proizvodi';
        $csv_putanja = $s['dir'] . '/cjenik-' . $oznaka . '_' . $base . '_' . str_pad($broj, 6, '0', STR_PAD_LEFT) . '_' . $stamp . '.csv';
        $xml_putanja = $s['dir'] . '/cjenik-' . $oznaka . '_' . $base . '_' . str_pad($broj, 6, '0', STR_PAD_LEFT) . '_' . $stamp . '.xml';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streams validated local feed files to bounded-memory derivative feeds.
        $ulaz = fopen($izvorni_csv, 'rb');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writes an archive feed inside the validated uploads directory.
        $izlaz = fopen($csv_putanja, 'wb');
        $xml = new XMLWriter();
        if (!$ulaz || !$izlaz || !$xml->openURI($xml_putanja)) {
            if ($ulaz) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the validated local input stream after an open failure.
                fclose($ulaz);
            }
            if ($izlaz) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the validated local output stream after an open failure.
                fclose($izlaz);
            }
            return new WP_Error('ai_izdvojeni_feed', 'Izdvojeni cjenik nije moguće otvoriti.');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes a UTF-8 BOM to the validated local export stream.
        fwrite($izlaz, "\xEF\xBB\xBF");
        $zaglavlje = fgetcsv($ulaz, 0, ';');
        if (!$zaglavlje) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the validated local input stream.
            fclose($ulaz);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the validated local output stream.
            fclose($izlaz);
            return new WP_Error('ai_izdvojeni_feed', 'Izvorni cjenik nema zaglavlje.');
        }
        $zaglavlje[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$zaglavlje[0]);
        fputcsv($izlaz, $zaglavlje, ';');
        $indeks_vrste = array_search('vrsta_stavke', $zaglavlje, true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('cjenik');
        $xml->writeAttribute('vrsta', $vrsta);
        $xml->writeAttribute('generirano', current_time(DATE_ATOM));
        $xml->startElement('objekt');
        foreach (array('naziv','adresa','vrsta_objekta','oznaka_objekta') as $kljuc) {
            $xml->writeElement($kljuc, $postavke[$kljuc]);
        }
        $xml->endElement();
        $xml->startElement($oznaka);
        while (($redak = fgetcsv($ulaz, 0, ';')) !== false) {
            if ($indeks_vrste === false || ($redak[$indeks_vrste] ?? '') !== $vrsta) {
                continue;
            }
            fputcsv($izlaz, $redak, ';');
            $xml->startElement($vrsta);
            foreach ($zaglavlje as $indeks => $kljuc) {
                $xml->writeElement($kljuc, (string)($redak[$indeks] ?? ''));
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the streamed derivative-feed input.
        fclose($ulaz);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the streamed derivative-feed output.
        fclose($izlaz);
        return array('csv' => $csv_putanja, 'xml' => $xml_putanja);
    }

    private static function zamijeni_aktualni($izvor, $cilj)
    {
        $tmp = $cilj . '.new';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Local atomic publication inside the validated uploads directory.
        if (!copy($izvor, $tmp)) {
            return false;
        }$bak = $cilj . '.bak';
        if (file_exists($cilj)) {
            wp_delete_file($bak);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic rename is required for rollback-safe feed publication.
            if (!rename($cilj, $bak)) {
                wp_delete_file($tmp);
                return false;
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic rename is required so readers never receive a partial feed.
        if (!rename($tmp, $cilj)) {
            if (file_exists($bak)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Restores the last valid feed after a failed atomic replacement.
                rename($bak, $cilj);
            }return false;
        }
        wp_delete_file($bak);
        return true;
    }
    private static function otkljucaj($lock)
    {
        delete_transient('ai_sidrene_generiranje');
        if ($lock) {
            flock($lock, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the exclusive lock stream opened during generation.
            fclose($lock);
        }
    }
    private static function ocisti_arhivu()
    {
        $cut = time() - max(31, (int)self::postavke()['retencija']) * DAY_IN_SECONDS;
        $s = self::spremiste();
        $datoteke = array_merge((array)glob($s['dir'] . '/cjenik_*.*'), (array)glob($s['dir'] . '/cjenik-*_*.*'));
        foreach ($datoteke as $f) {
            if (is_file($f) && filemtime($f) < $cut) {
                wp_delete_file($f);
            }
        }
    }
    private static function log($level, $component, $message, $context = array())
    {
        if (self::postavke()['debug'] !== '1' && $level === 'debug') {
            return;
        }if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, array('source' => 'ai-sidrene-cijene','component' => $component,'context' => $context));
        }
    }

    public static function rest_ruta()
    {
        register_rest_route(self::REST_NS, '/prices', array('methods' => 'GET','callback' => array(__CLASS__,'rest_cijene'),'permission_callback' => '__return_true','args' => array('page' => array('default' => 1,'sanitize_callback' => 'absint','validate_callback' => function ($v) {
                return $v >= 1;
        }),'per_page' => array('default' => 50,'sanitize_callback' => 'absint','validate_callback' => function ($v) {
            return $v >= 1 && $v <= 100;
        }))));
    }
    public static function rest_cijene($req)
    {
        $page = (int)$req['page'];
        $limit = (int)$req['per_page'];
        $putanja = self::spremiste()['csv'];
        if (!is_readable($putanja)) {
            return new WP_Error('ai_feed_nije_objavljen', 'Javni cjenik još nije objavljen.', array('status' => 503));
        }
        $rows = array();
        $zaglavlje = array();
        $pocetak = ($page - 1) * $limit;
        $redni_broj = 0;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reads the plugin's already generated public CSV without querying the live catalogue.
        $datoteka = fopen($putanja, 'rb');
        if (!$datoteka) {
            return new WP_Error('ai_feed_nedostupan', 'Javni cjenik trenutačno nije dostupan.', array('status' => 503));
        }
        while (($redak = fgetcsv($datoteka, 0, ';')) !== false) {
            if (!$zaglavlje) {
                $redak[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)($redak[0] ?? ''));
                $zaglavlje = $redak;
                continue;
            }
            if ($redni_broj >= $pocetak && count($rows) < $limit) {
                $rows[] = array_combine($zaglavlje, array_pad(array_slice($redak, 0, count($zaglavlje)), count($zaglavlje), ''));
            }
            $redni_broj++;
            if (count($rows) >= $limit) {
                break;
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the generated public CSV stream.
        fclose($datoteka);
        $ukupno = max(0, (int)get_option('ai_sidrene_broj_zapisa', 0));
        $stranica_ukupno = $ukupno > 0 ? (int)ceil($ukupno / $limit) : 0;
        $r = new WP_REST_Response($rows, 200);
        $r->header('X-WP-Total', $ukupno);
        $r->header('X-WP-TotalPages', $stranica_ukupno);
        $r->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=60');
        $r->header('X-Content-Type-Options', 'nosniff');
        $r->header('ETag', '"' . md5(wp_json_encode($rows)) . '"');
        return $r;
    }
    public static function registriraj_cli()
    {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('ai-sidrene generate', array(__CLASS__,'cli_generiraj'));
        }
    }
    public static function cli_generiraj()
    {
        $r = self::generiraj_cjenike('wp-cli');
        if (is_wp_error($r)) {
            WP_CLI::error($r->get_error_message());
        }WP_CLI::success(sprintf('Objavljeno %d zapisa; upozorenja: %d.', $r['zapisi'], $r['upozorenja']));
    }

    private static function url_radnje($a)
    {
        return wp_nonce_url(admin_url('admin-post.php?action=' . $a), $a);
    }
    private static function provjeri($a)
    {
        if (!self::ovlasten()) {
            wp_die('Nemate dopuštenje.');
        }check_admin_referer($a);
    }
    private static function preusmjeri($s)
    {
        wp_safe_redirect(admin_url('tools.php?page=' . self::ADMIN_SLUG . '&ai_status=' . sanitize_key($s)));
        exit;
    }
    public static function rucna_objava()
    {
        self::provjeri('ai_sidrene_objavi');
        self::preusmjeri(is_wp_error(self::generiraj_cjenike('manual')) ? 'greska' : 'objavljeno');
    }

    private static function provjeri_upravljanje_podacima($akcija)
    {
        if (!current_user_can('manage_options') || !current_user_can('activate_plugins')) {
            wp_die(esc_html__('Nemate dopuštenje za upravljanje podacima dodatka.', 'autoinovacije-sidrene-cijene'));
        }
        check_admin_referer($akcija);
    }

    private static function meta_kljucevi()
    {
        return array(
            self::META_CIJENA,
            self::META_DATUM,
            self::META_IZVOR,
            self::META_ZAKLJUCANO,
            self::META_PROVJERA,
            self::META_AUDIT,
            self::META_VRSTA,
            self::META_OBJAVA,
        );
    }

    private static function podaci_sigurnosne_kopije()
    {
        global $wpdb;

        $uzorak = $wpdb->esc_like('ai_sidrene_') . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A deliberate administrator export must include every current plugin option.
        $nazivi_opcija = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name", $uzorak));
        $opcije = array();
        foreach ($nazivi_opcija as $naziv) {
            $opcije[$naziv] = get_option($naziv);
        }
        if (get_option('ai_postavke', false) !== false) {
            $opcije['ai_postavke'] = get_option('ai_postavke');
        }

        $kljucevi = self::meta_kljucevi();
        $upit = $wpdb->prepare(
            "SELECT pm.post_id, p.post_type, p.post_title, pm.meta_key, pm.meta_value
            FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key IN (%s, %s, %s, %s, %s, %s, %s, %s)
            ORDER BY pm.post_id, pm.meta_id",
            $kljucevi[0],
            $kljucevi[1],
            $kljucevi[2],
            $kljucevi[3],
            $kljucevi[4],
            $kljucevi[5],
            $kljucevi[6],
            $kljucevi[7]
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- A deliberate administrator export reads the complete audit dataset; $upit is prepared immediately above.
        $retci = $wpdb->get_results($upit, ARRAY_A);
        $stavke = array();
        foreach ($retci as $redak) {
            $id = (int)$redak['post_id'];
            if (!isset($stavke[$id])) {
                $stavke[$id] = array(
                    'post_id' => $id,
                    'post_type' => (string)$redak['post_type'],
                    'post_title' => (string)$redak['post_title'],
                    'meta' => array(),
                );
            }
            $stavke[$id]['meta'][(string)$redak['meta_key']] = maybe_unserialize($redak['meta_value']);
        }

        $stranica_id = (int)get_option('ai_sidrene_stranica', 0);
        $stranica = $stranica_id ? get_post($stranica_id, ARRAY_A) : null;

        return array(
            'format' => 'autoinovacije-sidrene-backup',
            'format_version' => 1,
            'plugin_version' => AI_SIDRENE_VERZIJA,
            'exported_at' => gmdate('c'),
            'site_url' => home_url('/'),
            'options' => $opcije,
            'archive_page' => is_array($stranica) ? $stranica : null,
            'items' => array_values($stavke),
        );
    }

    private static function posalji_preuzimanje($putanja, $naziv, $tip)
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: ' . $tip);
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($naziv) . '"');
        header('Content-Length: ' . (string)filesize($putanja));
        header('X-Content-Type-Options: nosniff');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams the administrator-requested backup directly to the response.
        readfile($putanja);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only the temporary backup created by this request.
        @unlink($putanja);
        exit;
    }

    public static function izvezi_sigurnosnu_kopiju()
    {
        self::provjeri_upravljanje_podacima('ai_sidrene_sigurnosna_kopija');
        $podaci = self::podaci_sigurnosne_kopije();
        $json = wp_json_encode($podaci, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            wp_die(esc_html__('Sigurnosnu kopiju nije moguće izraditi.', 'autoinovacije-sidrene-cijene'));
        }

        $oznaka = gmdate('Ymd-His');
        if (class_exists('ZipArchive')) {
            $privremena = wp_tempnam('autoinovacije-sidrene-backup-' . $oznaka . '.zip');
            $zip = new ZipArchive();
            if ($privremena && $zip->open($privremena, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $zip->addFromString('podaci.json', $json);
                $spremiste = self::spremiste();
                $korijen = is_dir($spremiste['dir']) ? realpath($spremiste['dir']) : false;
                if ($korijen) {
                    try {
                        $iterator = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($korijen, FilesystemIterator::SKIP_DOTS),
                            RecursiveIteratorIterator::LEAVES_ONLY
                        );
                        foreach ($iterator as $datoteka) {
                            if (!$datoteka->isFile() || !in_array(strtolower($datoteka->getExtension()), array('csv', 'xml'), true)) {
                                continue;
                            }
                            $stvarna = $datoteka->getRealPath();
                            if (!$stvarna || stripos(wp_normalize_path($stvarna), trailingslashit(wp_normalize_path($korijen))) !== 0) {
                                continue;
                            }
                            $relativna = str_replace('\\', '/', substr($stvarna, strlen($korijen) + 1));
                            $zip->addFile($stvarna, 'cjenici/' . $relativna);
                        }
                    } catch (UnexpectedValueException $e) {
                        // JSON backup remains valid even if an archive directory becomes unavailable during export.
                    }
                }
                $zip->close();
                self::posalji_preuzimanje($privremena, 'autoinovacije-sidrene-backup-' . $oznaka . '.zip', 'application/zip');
            }
            if ($privremena && file_exists($privremena)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only a failed temporary ZIP backup before the JSON fallback.
                @unlink($privremena);
            }
        }

        $privremena = wp_tempnam('autoinovacije-sidrene-backup-' . $oznaka . '.json');
        if (!$privremena) {
            wp_die(esc_html__('Sigurnosnu kopiju nije moguće izraditi.', 'autoinovacije-sidrene-cijene'));
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes only the temporary administrator backup.
        if (file_put_contents($privremena, $json, LOCK_EX) === false) {
            wp_die(esc_html__('Sigurnosnu kopiju nije moguće zapisati.', 'autoinovacije-sidrene-cijene'));
        }
        self::posalji_preuzimanje($privremena, 'autoinovacije-sidrene-backup-' . $oznaka . '.json', 'application/json; charset=utf-8');
    }

    private static function obrisi_spremiste()
    {
        $spremiste = self::spremiste();
        if (!is_dir($spremiste['dir'])) {
            return true;
        }

        $upload = wp_upload_dir();
        $baza = realpath($upload['basedir']);
        $cilj = realpath($spremiste['dir']);
        if (!$baza || !$cilj) {
            return new WP_Error('ai_brisanje_putanja', __('Nije moguće potvrditi putanju javnih cjenika.', 'autoinovacije-sidrene-cijene'));
        }
        $baza_normalizirana = trailingslashit(wp_normalize_path($baza));
        $cilj_normaliziran = untrailingslashit(wp_normalize_path($cilj));
        if (stripos($cilj_normaliziran . '/', $baza_normalizirana) !== 0 || basename($cilj_normaliziran) !== 'ai-sidrene-cijene') {
            return new WP_Error('ai_brisanje_putanja', __('Sigurnosna provjera putanje javnih cjenika nije uspjela.', 'autoinovacije-sidrene-cijene'));
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($cilj, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $stavka) {
                $putanja = $stavka->getPathname();
                if ($stavka->isLink() || $stavka->isFile()) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Target is validated as the dedicated plugin uploads directory above.
                    if (!@unlink($putanja)) {
                        return new WP_Error('ai_brisanje_datoteke', __('Jednu ili više datoteka cjenika nije moguće izbrisati.', 'autoinovacije-sidrene-cijene'));
                    }
                } elseif ($stavka->isDir()) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Target is validated as the dedicated plugin uploads directory above.
                    if (!@rmdir($putanja)) {
                        return new WP_Error('ai_brisanje_mape', __('Jednu ili više mapa cjenika nije moguće izbrisati.', 'autoinovacije-sidrene-cijene'));
                    }
                }
            }
        } catch (UnexpectedValueException $e) {
            return new WP_Error('ai_brisanje_citanje', __('Mapu javnih cjenika nije moguće pročitati.', 'autoinovacije-sidrene-cijene'));
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Target is validated as the dedicated plugin uploads directory above.
        return @rmdir($cilj) ? true : new WP_Error('ai_brisanje_mape', __('Mapu javnih cjenika nije moguće izbrisati.', 'autoinovacije-sidrene-cijene'));
    }

    public static function trajno_obrisi_podatke()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) !== 'POST') {
            wp_die(esc_html__('Trajno brisanje dopušteno je samo putem administratorskog obrasca.', 'autoinovacije-sidrene-cijene'));
        }
        self::provjeri_upravljanje_podacima('ai_sidrene_trajno_obrisi');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capabilities are verified immediately above.
        $potvrda = isset($_POST['potvrda_brisanja']) ? sanitize_text_field(wp_unslash($_POST['potvrda_brisanja'])) : '';
        if ($potvrda !== 'TRAJNO OBRIŠI') {
            self::preusmjeri('brisanje_potvrda');
        }

        if (is_multisite()) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_plugin_active_for_network(plugin_basename(AI_SIDRENE_DATOTEKA))) {
                wp_die(esc_html__('Mrežno aktivan dodatak prvo treba deaktivirati u mrežnoj administraciji.', 'autoinovacije-sidrene-cijene'));
            }
        }

        wp_clear_scheduled_hook(self::CRON);
        wp_clear_scheduled_hook(self::MIGRACIJA);
        delete_transient('ai_sidrene_generiranje');

        $brisanje_spremista = self::obrisi_spremiste();
        if (is_wp_error($brisanje_spremista)) {
            wp_die(esc_html($brisanje_spremista->get_error_message()));
        }

        $stranica_id = (int)get_option('ai_sidrene_stranica', 0);
        $stranica = $stranica_id ? get_post($stranica_id) : get_page_by_path(self::ARHIVA_SLUG);
        if ($stranica && $stranica->post_type === 'page' && (has_shortcode($stranica->post_content, self::SHORTCODE) || has_shortcode($stranica->post_content, 'sidrene_cijene_arhiva'))) {
            if (!wp_delete_post($stranica->ID, true)) {
                wp_die(esc_html__('Javnu stranicu arhive nije moguće izbrisati; metapodaci i postavke nisu uklonjeni.', 'autoinovacije-sidrene-cijene'));
            }
        }

        foreach (self::meta_kljucevi() as $kljuc) {
            delete_metadata('post', 0, $kljuc, '', true);
        }

        global $wpdb;
        $uzorak = $wpdb->esc_like('ai_sidrene_') . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberate administrator-requested removal must enumerate every plugin option.
        $nazivi_opcija = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $uzorak));
        foreach ($nazivi_opcija as $naziv) {
            delete_option($naziv);
        }
        delete_option('ai_postavke');

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        deactivate_plugins(plugin_basename(AI_SIDRENE_DATOTEKA), true);
        wp_safe_redirect(admin_url('plugins.php?deactivate=true'));
        exit;
    }

    public static function spremi_postavke()
    {
        self::provjeri('ai_sidrene_spremi');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by self::provjeri() immediately above.
        $x = wp_unslash($_POST);
        $brand = isset($x['brand_izvor']) ? sanitize_text_field($x['brand_izvor']) : '';
        $ean = isset($x['ean_meta']) ? sanitize_key($x['ean_meta']) : '';
        $p = array('naziv' => sanitize_text_field($x['naziv'] ?? ''),'adresa' => sanitize_text_field($x['adresa'] ?? ''),'vrsta_objekta' => sanitize_text_field($x['vrsta_objekta'] ?? ''),'oznaka_objekta' => sanitize_text_field($x['oznaka_objekta'] ?? ''),'zadani_datum' => self::datum($x['zadani_datum'] ?? '') ?: '10.09.2026.','auto' => isset($x['auto']) ? '1' : '0','footer' => isset($x['footer']) ? '1' : '0','katalog' => isset($x['katalog']) ? '1' : '0','ukljuci_skrivene' => isset($x['ukljuci_skrivene']) ? '1' : '0','retencija' => (string)min(3650, max(31, absint($x['retencija'] ?? 31))),'brand_izvor' => preg_match('/^(taxonomy|meta):[a-z0-9_\-]+$/', $brand) ? $brand : 'taxonomy:product_brand','ean_meta' => $ean,'debug' => isset($x['debug']) ? '1' : '0');
        update_option(self::OPCIJE, $p, false);
        wp_clear_scheduled_hook(self::CRON);
        self::rasporedi_cron();
        self::oznaci_promjenu();
        self::preusmjeri('postavke');
    }

    public static function izvezi_sidrene_csv()
    {
        self::provjeri('ai_sidrene_izvoz');
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=ai-sidrene-import.csv');
        header('X-Content-Type-Options: nosniff');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct response streaming avoids buffering a potentially large catalogue.
        $izlaz = fopen('php://output', 'w');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes the UTF-8 BOM to the HTTP response stream.
        fwrite($izlaz, "\xEF\xBB\xBF");
        fputcsv($izlaz, self::zaglavlje_importa(), ';');
        foreach (self::retci_predloska() as $redak) {
            fputcsv($izlaz, $redak, ';');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the HTTP response stream opened above.
        fclose($izlaz);
        exit;
    }

    private static function zaglavlje_importa()
    {
        return array('product_id','variation_id','sku','ean','item_type','publication','anchor_date','anchor_price','anchor_source','anchor_locked');
    }

    private static function retci_predloska()
    {
        $page = 1;
        do {
            $result = self::export_ids($page);
            foreach ($result->products as $id) {
                foreach (self::izvozne_stavke($id) as $p) {
                    list($cijena,$datum,$izvor,$postoji) = self::sidrena($p);
                    yield array($p->get_parent_id() ?: $p->get_id(),$p->get_parent_id() ? $p->get_id() : 0,self::csv_celija($p->get_sku()),self::csv_celija(self::ean($p)),self::vrsta_stavke($p),self::naslijedena_meta($p, self::META_OBJAVA),$postoji ? $datum : '',$postoji ? $cijena : '',$postoji ? $izvor : 'CSV_import',$p->get_meta(self::META_ZAKLJUCANO, true) === '0' ? '0' : '1');
                }
            }
            $page++;
        } while ($page <= $result->max_num_pages);
    }

    public static function izvezi_sidrene_xlsx()
    {
        self::provjeri('ai_sidrene_izvoz_xlsx');
        $rezultat = AI_Sidrene_XLSX::posalji('ai-sidrene-cijene-predlozak.xlsx', self::zaglavlje_importa(), self::retci_predloska());
        if (is_wp_error($rezultat)) {
            wp_die(esc_html($rezultat->get_error_message()));
        }
    }

    public static function uvezi_sidrene_datoteku()
    {
        self::provjeri('ai_sidrene_uvoz');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is verified above; individual upload fields are validated and sanitized below.
        $upload = isset($_FILES['ai_csv']) && is_array($_FILES['ai_csv']) ? wp_unslash($_FILES['ai_csv']) : array();
        $privremena = isset($upload['tmp_name']) ? sanitize_text_field($upload['tmp_name']) : '';
        $greska = isset($upload['error']) ? absint($upload['error']) : UPLOAD_ERR_NO_FILE;
        $velicina = isset($upload['size']) ? absint($upload['size']) : 0;
        if ($privremena === '' || $greska !== UPLOAD_ERR_OK || $velicina > 10485760 || !is_uploaded_file($privremena)) {
            self::preusmjeri('uvoz_greska');
        }
        $naziv = isset($upload['name']) ? sanitize_file_name($upload['name']) : '';
        $ekstenzija = strtolower(pathinfo($naziv, PATHINFO_EXTENSION));
        if (!in_array($ekstenzija, array('csv','xlsx'), true)) {
            self::preusmjeri('uvoz_greska');
        }
        if ($ekstenzija === 'xlsx') {
            $retci = AI_Sidrene_XLSX::procitaj($privremena);
            if (is_wp_error($retci)) {
                self::preusmjeri('uvoz_greska');
            }
        } else {
            $retci = array();
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Temporary upload path is verified with is_uploaded_file() above and is read as a stream.
            $datoteka = fopen($privremena, 'r');
            if (!$datoteka) {
                self::preusmjeri('uvoz_greska');
            }
            while (($redak = fgetcsv($datoteka, 0, ';')) !== false) {
                $retci[] = $redak;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the verified upload stream opened above.
            fclose($datoteka);
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by self::provjeri() above.
        $primijeni = isset($_POST['commit']) && '1' === sanitize_key(wp_unslash($_POST['commit']));
        self::obradi_retke_importa($retci, $primijeni);
    }

    private static function obradi_retke_importa($retci, $primijeni)
    {
        if (!$retci) {
            self::preusmjeri('uvoz_greska');
        }
        $zaglavlje = array_shift($retci);
        $zaglavlje[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)($zaglavlje[0] ?? ''));
        if (array_diff(array('product_id','variation_id','sku','anchor_date','anchor_price','anchor_source'), $zaglavlje)) {
            self::preusmjeri('uvoz_greska');
        }
        $stat = array('valid' => 0,'invalid' => 0,'not_found' => 0,'duplicate' => 0,'commit' => (bool)$primijeni);
        $videni = array();
        foreach ($retci as $vrijednosti) {
            if (!array_filter($vrijednosti, 'strlen')) {
                continue;
            }
            $redak = array_combine($zaglavlje, array_pad(array_slice($vrijednosti, 0, count($zaglavlje)), count($zaglavlje), ''));
            $id = absint($redak['variation_id']) ?: absint($redak['product_id']);
            if (!$id && trim($redak['sku']) !== '') {
                $id = wc_get_product_id_by_sku(wc_clean($redak['sku']));
            }
            if ($id && isset($videni[$id])) {
                $stat['duplicate']++;
                continue;
            }
            $p = $id ? wc_get_product($id) : false;
            if (!$p) {
                $stat['not_found']++;
                continue;
            }
            $videni[$id] = true;
            $cijena = wc_format_decimal(wc_clean($redak['anchor_price']));
            $datum = self::datum(sanitize_text_field($redak['anchor_date']));
            $izvor = sanitize_key($redak['anchor_source']);
            $vrsta = sanitize_key($redak['item_type'] ?? '');
            $objava = sanitize_key($redak['publication'] ?? '');
            if ($cijena === '' || !is_numeric($cijena) || (float)$cijena < 0 || !$datum || !array_key_exists($izvor, self::izvori()) || ($vrsta !== '' && !in_array($vrsta, array('proizvod','usluga'), true)) || ($objava !== '' && !in_array($objava, array('ukljuci','iskljuci'), true))) {
                $stat['invalid']++;
                continue;
            }
            $stat['valid']++;
            if ($primijeni) {
                self::spremi_sidrenu($p, array('cijena' => $cijena,'datum' => $datum,'izvor' => $izvor ?: 'CSV_import','lock' => ($redak['anchor_locked'] ?? '1') === '0' ? '0' : '1','vrsta' => $vrsta,'objava' => $objava));
                $p->save();
            }
        }
        update_option('ai_sidrene_import_rezultat', $stat, false);
        if ($primijeni) {
            self::oznaci_promjenu();
        }
        self::preusmjeri($primijeni ? 'uvezeno' : 'provjereno');
    }

    public static function potvrdi_trenutne_cijene()
    {
        self::provjeri('ai_sidrene_potvrdi_cijene');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by self::provjeri() immediately above.
        if (empty($_POST['potvrda']) || sanitize_text_field(wp_unslash($_POST['potvrda'])) !== 'POTVRĐUJEM') {
            self::preusmjeri('potvrda_greska');
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by self::provjeri() above.
        $nacin_ulaz = isset($_POST['nacin_cijene']) ? sanitize_key(wp_unslash($_POST['nacin_cijene'])) : '';
        $nacin = $nacin_ulaz === 'current' ? 'current' : 'regular';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by self::provjeri() above.
        $datum = self::datum(isset($_POST['datum_potvrde']) ? sanitize_text_field(wp_unslash($_POST['datum_potvrde'])) : '');
        if (!$datum) {
            self::preusmjeri('potvrda_greska');
        }
        $rezultat = array('popunjeno' => 0,'postojalo' => 0,'bez_cijene' => 0);
        $page = 1;
        do {
            $result = self::export_ids($page);
            foreach ($result->products as $id) {
                foreach (self::izvozne_stavke($id) as $p) {
                    if ($p->get_meta(self::META_CIJENA, true) !== '') {
                        $rezultat['postojalo']++;
                        continue;
                    }
                    $sirova = $nacin === 'current' ? $p->get_price() : $p->get_regular_price();
                    $vrijednost = $sirova === '' ? '' : self::vidljiva_cijena($p, $sirova);
                    if ($vrijednost === '') {
                        $rezultat['bez_cijene']++;
                        continue;
                    }
                    self::spremi_sidrenu($p, array('cijena' => $vrijednost,'datum' => $datum,'izvor' => 'manual_confirmation','lock' => '1'));
                    $p->save();
                    $rezultat['popunjeno']++;
                }
            }
            $page++;
        } while ($page <= $result->max_num_pages);
        update_option('ai_sidrene_potvrda_rezultat', $rezultat, false);
        self::oznaci_promjenu();
        self::preusmjeri('potvrdeno');
    }

    public static function bulk_radnje($a)
    {
        $a['ai_sidrene_zakljucaj'] = 'Zaključaj sidrene cijene';
        $a['ai_sidrene_otkljucaj'] = 'Otključaj sidrene cijene';
        return $a;
    }
    public static function obradi_bulk($url, $action, $ids)
    {
        if (!in_array($action, array('ai_sidrene_zakljucaj','ai_sidrene_otkljucaj'), true) || !self::ovlasten()) {
            return $url;
        }$v = $action === 'ai_sidrene_zakljucaj' ? '1' : '0';
        foreach ($ids as $id) {
            update_post_meta($id, self::META_ZAKLJUCANO, $v);
        }return add_query_arg('ai_sidrene_bulk', count($ids), $url);
    }
    public static function filtriraj_proizvode_bez_sidrene($q)
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter does not change state.
        $nedostaje = isset($_GET['ai_anchor_missing']) ? absint(wp_unslash($_GET['ai_anchor_missing'])) : 0;
        if (!is_admin() || !$q->is_main_query() || $q->get('post_type') !== 'product' || !$nedostaje) {
            return;
        }$q->set('meta_query', array(array('key' => self::META_CIJENA,'compare' => 'NOT EXISTS')));
    }
    public static function dodaj_filter_bez_sidrene($views)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only live admin counter; request-local result must reflect current product metadata.
        $n = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON p.ID=m.post_id AND m.meta_key=%s WHERE p.post_type='product' AND p.post_status='publish' AND m.post_id IS NULL", self::META_CIJENA));
        $views['ai_missing'] = '<a href="' . esc_url(admin_url('edit.php?post_type=product&ai_anchor_missing=1')) . '">Bez sidrene cijene <span class="count">(' . $n . ')</span></a>';
        return $views;
    }

    public static function arhiva_shortcode()
    {
        $s = self::spremiste();
        $files = array_merge((array)glob($s['dir'] . '/cjenik_*.*'), (array)glob($s['dir'] . '/cjenik-*_*.*'));
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        $h = '<div class="ai-arhiva"><h2>Digitalni cjenici</h2>';
        if (file_exists($s['csv'])) {
            $h .= '<p><strong>Sve stavke:</strong> <a href="' . esc_url($s['csv_url']) . '">CSV</a> · <a href="' . esc_url($s['xml_url']) . '">XML</a></p>';
        }
        if (file_exists($s['proizvodi_csv'])) {
            $h .= '<p><strong>Proizvodi:</strong> <a href="' . esc_url($s['proizvodi_csv_url']) . '">CSV</a> · <a href="' . esc_url($s['proizvodi_xml_url']) . '">XML</a></p>';
        }
        if (file_exists($s['usluge_csv'])) {
            $h .= '<p><strong>Usluge:</strong> <a href="' . esc_url($s['usluge_csv_url']) . '">CSV</a> · <a href="' . esc_url($s['usluge_xml_url']) . '">XML</a></p>';
        }$h .= '<ul>';
        foreach ($files as $f) {
            $n = basename($f);
            $h .= '<li><a href="' . esc_url(trailingslashit($s['url']) . rawurlencode($n)) . '">' . esc_html($n) . '</a> <small>' . esc_html(date_i18n('d.m.Y. H:i', filemtime($f))) . '</small></li>';
        }return $h . '</ul></div>';
    }
    public static function footer_link()
    {
        $id = (int)get_option('ai_sidrene_stranica');
        if (self::postavke()['footer'] === '1' && $id) {
            echo '<div style="text-align:center;padding:10px"><a href="' . esc_url(get_permalink($id)) . '">Cjenik i arhiva cijena</a></div>';
        }
    }

    private static function statistika()
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT
                COUNT(DISTINCT p.ID) AS ukupno,
                COUNT(DISTINCT CASE
                    WHEN (COALESCE(cijena.meta_value, '') <> '' AND COALESCE(datum.meta_value, '') <> '')
                      OR (p.post_type = 'product_variation' AND COALESCE(roditelj_cijena.meta_value, '') <> '' AND COALESCE(roditelj_datum.meta_value, '') <> '')
                    THEN p.ID END) AS ima,
                COUNT(DISTINCT CASE
                    WHEN NOT (
                        (COALESCE(cijena.meta_value, '') <> '' AND COALESCE(datum.meta_value, '') <> '')
                        OR (p.post_type = 'product_variation' AND COALESCE(roditelj_cijena.meta_value, '') <> '' AND COALESCE(roditelj_datum.meta_value, '') <> '')
                    ) AND CAST(redovna.meta_value AS DECIMAL(20,6)) > 0
                    THEN p.ID END) AS spremno
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} cijena ON cijena.post_id = p.ID AND cijena.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} datum ON datum.post_id = p.ID AND datum.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} roditelj_cijena ON roditelj_cijena.post_id = p.post_parent AND roditelj_cijena.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} roditelj_datum ON roditelj_datum.post_id = p.post_parent AND roditelj_datum.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} redovna ON redovna.post_id = p.ID AND redovna.meta_key = '_regular_price'
            WHERE p.post_status = 'publish'
              AND (
                  p.post_type = 'product_variation'
                  OR (
                      p.post_type = 'product'
                      AND NOT EXISTS (
                          SELECT 1 FROM {$wpdb->posts} v
                          WHERE v.post_parent = p.ID
                            AND v.post_type = 'product_variation'
                            AND v.post_status = 'publish'
                      )
                  )
              )",
            self::META_CIJENA,
            self::META_DATUM,
            self::META_CIJENA,
            self::META_DATUM
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Read-only aggregate query was prepared immediately above and must reflect live metadata.
        $redak = $wpdb->get_row($sql, ARRAY_A);
        $ukupno = (int)($redak['ukupno'] ?? 0);
        $ima = (int)($redak['ima'] ?? 0);
        $spremno = (int)($redak['spremno'] ?? 0);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only live aggregate without external input.
        $roditelji = (int)$wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->posts} v ON v.post_parent = p.ID
                AND v.post_type = 'product_variation'
                AND v.post_status = 'publish'
             WHERE p.post_type = 'product' AND p.post_status = 'publish'"
        );

        return array(
            'ukupno' => $ukupno,
            'ima' => $ima,
            'nema' => max(0, $ukupno - $ima),
            'spremno' => $spremno,
            'pregled' => max(0, $ukupno - $ima - $spremno),
            'roditelji' => $roditelji,
        );
    }
    private static function notice($status)
    {
        $m = array('objavljeno' => array('success','CSV i XML uspješno su objavljeni.'),'greska' => array('error','Generiranje nije uspjelo; zadnji valjani feed ostao je dostupan.'),'postavke' => array('success','Postavke su spremljene.'),'uvoz_greska' => array('error','CSV/XLSX nije valjan ili ga nije moguće učitati.'),'provjereno' => array('info','Dry-run je završen; provjerite rezultate pa ponovite uvoz s uključenom potvrdom.'),'uvezeno' => array('success','Datoteka sidrenih cijena uspješno je uvezena.'),'potvrdeno' => array('success','Prazne sidrene cijene popunjene su potvrđenim cijenama. Postojeće vrijednosti nisu mijenjane.'),'potvrda_greska' => array('error','Potvrda nije prihvaćena. Upišite točno POTVRĐUJEM i odaberite valjani datum.'),'brisanje_potvrda' => array('error','Podaci nisu izbrisani. Za trajno brisanje upišite točno TRAJNO OBRIŠI.'));
        if (isset($m[$status])) {
            echo '<div class="ai-alert ai-alert-' . esc_attr($m[$status][0]) . '"><p>' . esc_html($m[$status][1]) . '</p></div>';
        }
    }

    private static function url_pregleda($prikaz)
    {
        return add_query_arg(
            array('page' => self::ADMIN_SLUG, 'ai_prikaz' => $prikaz),
            admin_url('tools.php')
        );
    }

    private static function stavke_pregleda($prikaz, $trazi, $stranica, $po_stranici = 50)
    {
        global $wpdb;

        if (!in_array($prikaz, array('sa_sidrenom', 'bez_sidrene', 'rucni_pregled'), true)) {
            return array('stavke' => array(), 'ukupno' => 0, 'stranice' => 0);
        }

        $uzorak = '%' . $wpdb->esc_like($trazi) . '%';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only admin report must reflect current product metadata.
        $ukupno = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->posts} roditelj ON roditelj.ID = p.post_parent
            LEFT JOIN {$wpdb->postmeta} cijena ON cijena.post_id = p.ID AND cijena.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} datum ON datum.post_id = p.ID AND datum.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} roditelj_cijena ON roditelj_cijena.post_id = p.post_parent AND roditelj_cijena.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} roditelj_datum ON roditelj_datum.post_id = p.post_parent AND roditelj_datum.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} redovna ON redovna.post_id = p.ID AND redovna.meta_key = '_regular_price'
            LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
            WHERE p.post_status = 'publish'
              AND (
                  p.post_type = 'product_variation'
                  OR (p.post_type = 'product' AND NOT EXISTS (
                      SELECT 1 FROM {$wpdb->posts} v
                       WHERE v.post_parent = p.ID AND v.post_type = 'product_variation' AND v.post_status = 'publish'
                   ))
               )
              AND (
                  (%s = 'sa_sidrenom' AND ((COALESCE(cijena.meta_value, '') <> '' AND COALESCE(datum.meta_value, '') <> '')
                      OR (p.post_type = 'product_variation' AND COALESCE(roditelj_cijena.meta_value, '') <> '' AND COALESCE(roditelj_datum.meta_value, '') <> '')))
                  OR (%s = 'bez_sidrene' AND NOT ((COALESCE(cijena.meta_value, '') <> '' AND COALESCE(datum.meta_value, '') <> '')
                      OR (p.post_type = 'product_variation' AND COALESCE(roditelj_cijena.meta_value, '') <> '' AND COALESCE(roditelj_datum.meta_value, '') <> '')))
                  OR (%s = 'rucni_pregled' AND NOT ((COALESCE(cijena.meta_value, '') <> '' AND COALESCE(datum.meta_value, '') <> '')
                      OR (p.post_type = 'product_variation' AND COALESCE(roditelj_cijena.meta_value, '') <> '' AND COALESCE(roditelj_datum.meta_value, '') <> ''))
                      AND COALESCE(CAST(redovna.meta_value AS DECIMAL(20,6)), 0) <= 0)
              )
              AND (%s = '' OR p.post_title LIKE %s OR roditelj.post_title LIKE %s OR sku.meta_value LIKE %s)",
            self::META_CIJENA,
            self::META_DATUM,
            self::META_CIJENA,
            self::META_DATUM,
            $prikaz,
            $prikaz,
            $prikaz,
            $trazi,
            $uzorak,
            $uzorak,
            $uzorak
        ));

        $pomak = max(0, ($stranica - 1) * $po_stranici);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paginated read-only admin report must reflect current product metadata.
        $stavke = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_type,
                MAX(COALESCE(NULLIF(cijena.meta_value, ''), IF(p.post_type = 'product_variation', roditelj_cijena.meta_value, ''))) AS sidrena_cijena,
                MAX(COALESCE(NULLIF(datum.meta_value, ''), IF(p.post_type = 'product_variation', roditelj_datum.meta_value, ''))) AS sidreni_datum,
                MAX(redovna.meta_value) AS redovna_cijena,
                MAX(sku.meta_value) AS sku
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->posts} roditelj ON roditelj.ID = p.post_parent
                LEFT JOIN {$wpdb->postmeta} cijena ON cijena.post_id = p.ID AND cijena.meta_key = %s
                LEFT JOIN {$wpdb->postmeta} datum ON datum.post_id = p.ID AND datum.meta_key = %s
                LEFT JOIN {$wpdb->postmeta} roditelj_cijena ON roditelj_cijena.post_id = p.post_parent AND roditelj_cijena.meta_key = %s
                LEFT JOIN {$wpdb->postmeta} roditelj_datum ON roditelj_datum.post_id = p.post_parent AND roditelj_datum.meta_key = %s
                LEFT JOIN {$wpdb->postmeta} redovna ON redovna.post_id = p.ID AND redovna.meta_key = '_regular_price'
                LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
                WHERE p.post_status = 'publish'
                  AND (
                      p.post_type = 'product_variation'
                      OR (p.post_type = 'product' AND NOT EXISTS (
                          SELECT 1 FROM {$wpdb->posts} v
                          WHERE v.post_parent = p.ID AND v.post_type = 'product_variation' AND v.post_status = 'publish'
                      ))
                  )
                  AND (
                      (%s = 'sa_sidrenom' AND ((COALESCE(cijena.meta_value, '') <> '' AND COALESCE(datum.meta_value, '') <> '')
                          OR (p.post_type = 'product_variation' AND COALESCE(roditelj_cijena.meta_value, '') <> '' AND COALESCE(roditelj_datum.meta_value, '') <> '')))
                      OR (%s = 'bez_sidrene' AND NOT ((COALESCE(cijena.meta_value, '') <> '' AND COALESCE(datum.meta_value, '') <> '')
                          OR (p.post_type = 'product_variation' AND COALESCE(roditelj_cijena.meta_value, '') <> '' AND COALESCE(roditelj_datum.meta_value, '') <> '')))
                      OR (%s = 'rucni_pregled' AND NOT ((COALESCE(cijena.meta_value, '') <> '' AND COALESCE(datum.meta_value, '') <> '')
                          OR (p.post_type = 'product_variation' AND COALESCE(roditelj_cijena.meta_value, '') <> '' AND COALESCE(roditelj_datum.meta_value, '') <> ''))
                          AND COALESCE(CAST(redovna.meta_value AS DECIMAL(20,6)), 0) <= 0)
                  )
                  AND (%s = '' OR p.post_title LIKE %s OR roditelj.post_title LIKE %s OR sku.meta_value LIKE %s)
                GROUP BY p.ID, p.post_type
                ORDER BY COALESCE(MAX(roditelj.post_title), MAX(p.post_title)) ASC, p.post_parent ASC, p.menu_order ASC, p.ID ASC
                LIMIT %d OFFSET %d",
                self::META_CIJENA,
                self::META_DATUM,
                self::META_CIJENA,
                self::META_DATUM,
                $prikaz,
                $prikaz,
                $prikaz,
                $trazi,
                $uzorak,
                $uzorak,
                $uzorak,
                $po_stranici,
                $pomak
            ),
            ARRAY_A
        );

        return array(
            'stavke' => is_array($stavke) ? $stavke : array(),
            'ukupno' => $ukupno,
            'stranice' => (int)ceil($ukupno / $po_stranici),
        );
    }

    private static function admin_pregled_stavki($prikaz)
    {
        $naslovi = array(
            'sa_sidrenom' => 'Artikli sa sidrenom cijenom',
            'bez_sidrene' => 'Artikli kojima nedostaje sidrena cijena',
            'rucni_pregled' => 'Artikli za ručni pregled',
        );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search does not change state.
        $trazi = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination does not change state.
        $stranica = isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1;
        $rezultat = self::stavke_pregleda($prikaz, $trazi, $stranica);
        ?>
        <div class="wrap ai-wrap ai-review-wrap">
          <div class="ai-head"><div><p class="ai-eyebrow">AUTOINOVACIJE AI</p><h1><?php echo esc_html($naslovi[$prikaz]);?></h1></div><span class="ai-badge"><?php echo (int)$rezultat['ukupno'];?> stavki</span></div>
          <p><a href="<?php echo esc_url(admin_url('tools.php?page=' . self::ADMIN_SLUG));?>">&larr; Natrag na upravljačku ploču</a></p>
          <nav class="ai-review-tabs" aria-label="Filtri sidrenih cijena">
            <?php foreach ($naslovi as $kljuc => $naslov) :?>
              <a class="<?php echo $prikaz === $kljuc ? 'is-active' : '';?>" href="<?php echo esc_url(self::url_pregleda($kljuc));?>"><?php echo esc_html($naslov);?></a>
            <?php endforeach;?>
          </nav>
          <form class="ai-review-search" method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::ADMIN_SLUG);?>">
            <input type="hidden" name="ai_prikaz" value="<?php echo esc_attr($prikaz);?>">
            <label class="screen-reader-text" for="ai-review-search">Pretraži artikle</label>
            <input id="ai-review-search" type="search" name="s" value="<?php echo esc_attr($trazi);?>" placeholder="Naziv ili SKU">
            <button class="button" type="submit">Pretraži</button>
          </form>
          <div class="ai-card ai-review-card">
            <table class="widefat striped ai-review-table">
              <thead><tr><th>Artikl</th><th>Vrsta</th><th>SKU</th><th>Redovna cijena</th><th>Sidrena cijena</th><th>Datum</th><th>Status</th><th></th></tr></thead>
              <tbody>
              <?php if (!$rezultat['stavke']) :?>
                <tr><td colspan="8">Nema artikala koji odgovaraju odabranom filtru.</td></tr>
              <?php else : foreach ($rezultat['stavke'] as $redak) :
                  $proizvod = wc_get_product((int)$redak['ID']);
                  if (!$proizvod) {
                      continue;
                  }
                  $varijacija = $redak['post_type'] === 'product_variation';
                  $uredi_id = $varijacija ? $proizvod->get_parent_id() : $proizvod->get_id();
                  $uredi = get_edit_post_link($uredi_id, '');
                  $sidrena_cijena = (string)($redak['sidrena_cijena'] ?? '');
                  $sidreni_datum = (string)($redak['sidreni_datum'] ?? '');
                  $ima = $sidrena_cijena !== '' && $sidreni_datum !== '';
                  $redovna = (float)$redak['redovna_cijena'];
                  $status = $ima ? 'Sidrena evidentirana' : ($redovna > 0 ? 'Spremno za potvrdu' : 'Potreban ručni pregled');
                  ?>
                <tr>
                  <td><strong><?php echo esc_html($proizvod->get_name());?></strong><br><small>ID <?php echo (int)$proizvod->get_id();?></small></td>
                  <td><?php echo $varijacija ? 'Varijacija' : 'Proizvod';?></td>
                  <td><?php echo esc_html($redak['sku'] ?: '—');?></td>
                  <td><?php echo $redovna > 0 ? wp_kses_post(wc_price($redovna)) : '—';?></td>
                  <td><?php echo $ima ? wp_kses_post(wc_price((float)$sidrena_cijena)) : '—';?></td>
                  <td><?php echo $ima ? esc_html(self::datum($sidreni_datum)) : '—';?></td>
                  <td><span class="ai-review-status <?php echo $ima ? 'is-good' : ($redovna > 0 ? 'is-warning' : 'is-danger');?>"><?php echo esc_html($status);?></span></td>
                  <td><?php if ($uredi) :?><a class="button button-small" href="<?php echo esc_url($uredi);?>"><?php echo $varijacija ? 'Uredi varijacije' : 'Uredi';?></a><?php endif;?></td>
                </tr>
              <?php endforeach; endif;?>
              </tbody>
            </table>
          </div>
          <?php if ($rezultat['stranice'] > 1) :?>
            <div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post(paginate_links(array(
                'base' => add_query_arg(array('page' => self::ADMIN_SLUG, 'ai_prikaz' => $prikaz, 's' => $trazi, 'paged' => '%#%'), admin_url('tools.php')),
                'format' => '', 'current' => $stranica, 'total' => $rezultat['stranice'],
            )));?></div></div>
          <?php endif;?>
        </div>
        <?php
    }

    public static function admin_stranica()
    {
        if (!self::ovlasten()) {
            wp_die('Nemate dopuštenje.');
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin view selector does not change state.
        $prikaz = isset($_GET['ai_prikaz']) ? sanitize_key(wp_unslash($_GET['ai_prikaz'])) : '';
        if (in_array($prikaz, array('sa_sidrenom', 'bez_sidrene', 'rucni_pregled'), true)) {
            self::admin_pregled_stavki($prikaz);
            return;
        }
        self::rasporedi_cron();
        $p = self::postavke();
        $s = self::spremiste();
        $st = self::statistika();
        $dirty = get_option(self::PROMIJENJENO, '1') === '1';
        $last = (int)get_option('ai_sidrene_zadnja_objava');
        $next = wp_next_scheduled(self::CRON);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status notice does not change state.
        $status = isset($_GET['ai_status']) ? sanitize_key(wp_unslash($_GET['ai_status'])) : '';
        $valuta_razlicita = function_exists('get_woocommerce_currency') && get_woocommerce_currency() !== 'EUR';
        $nedostaju_postavke = trim((string)$p['naziv']) === ''
            || trim((string)$p['adresa']) === ''
            || trim((string)$p['vrsta_objekta']) === ''
            || trim((string)$p['oznaka_objekta']) === '';
        $zadnja_greska = (string)get_option('ai_sidrene_zadnja_greska', '');
        ?>
      <div class="wrap ai-wrap">
      <div class="ai-head"><div><p class="ai-eyebrow">AUTOINOVACIJE AI</p><h1>Sidrene cijene i cjenici</h1></div><span class="ai-badge">Verzija <?php echo esc_html(AI_SIDRENE_VERZIJA);?></span></div>
      <div class="ai-alerts" aria-live="polite">
        <?php if (!($status === 'greska' && $nedostaju_postavke)) {
            self::notice($status);
        }?>
        <?php if ($valuta_razlicita) :?>
          <div class="ai-alert ai-alert-warning"><p><strong>Valuta webshopa je <?php echo esc_html(get_woocommerce_currency());?>.</strong> Prije produkcije provjerite WooCommerce valutu i povijesne cijene. Dodatak valutu neće automatski mijenjati.</p></div>
        <?php endif;?>
        <?php if ($nedostaju_postavke) :?>
          <div class="ai-alert ai-alert-error"><p><strong>Objava je privremeno blokirana.</strong> U postavkama unesite naziv, adresu, vrstu i oznaku objekta.</p></div>
        <?php elseif ($zadnja_greska !== '') :?>
          <div class="ai-alert ai-alert-error"><p><?php echo esc_html($zadnja_greska);?></p></div>
        <?php endif;?>
      </div>
      <section class="ai-hero <?php echo $dirty ? '' : 'ok';?>"><h2><?php echo $dirty ? 'Cjenik čeka objavu' : 'Cjenik je ažuran';?></h2><p><?php echo $dirty ? 'Podaci su promijenjeni. Objavite novi digitalni cjenik.' : 'Aktualni CSV i XML sadrže zadnje objavljene podatke.';?></p><a class="ai-btn" href="<?php echo esc_url(self::url_radnje('ai_sidrene_objavi'));?>">Objavi CSV i XML</a></section>
      <div class="ai-stats">
        <div class="ai-stat"><span>Prodajne stavke</span><strong><?php echo esc_html((string) $st['ukupno']);?></strong></div>
        <a class="ai-stat ai-stat-link ai-stat-good" href="<?php echo esc_url(self::url_pregleda('sa_sidrenom'));?>"><span>Sa sidrenom</span><strong><?php echo esc_html((string) $st['ima']);?></strong><small>Otvori popis artikala &rarr;</small></a>
        <a class="ai-stat ai-stat-link ai-stat-warning" href="<?php echo esc_url(self::url_pregleda('bez_sidrene'));?>"><span>Nedostaje sidrena</span><strong><?php echo esc_html((string) $st['nema']);?></strong><small><?php echo esc_html((string) $st['spremno']);?> spremno za potvrdu &rarr;</small></a>
        <a class="ai-stat ai-stat-link <?php echo $st['pregled'] ? 'ai-stat-danger' : 'ai-stat-good';?>" href="<?php echo esc_url(self::url_pregleda('rucni_pregled'));?>"><span>Za ručni pregled</span><strong><?php echo esc_html((string) $st['pregled']);?></strong><small>nulta ili prazna cijena &rarr;</small></a>
        <div class="ai-stat"><span>Zapisa u feedu</span><strong><?php echo (int)get_option('ai_sidrene_broj_zapisa', 0);?></strong></div>
      </div>
      <p class="ai-context"><strong><?php echo esc_html((string) $st['roditelji']);?></strong> roditeljska varijabilna proizvoda ne računaju se kao zasebne prodajne stavke.</p>
      <div class="ai-meta"><span><strong>Zadnja objava:</strong> <?php echo $last ? esc_html(wp_date('d.m.Y. H:i:s', $last)) : 'nikad';?></span><span><strong>Trajanje:</strong> <?php echo esc_html((string)get_option('ai_sidrene_trajanje', '—'));?> s</span><span><strong>Sljedeća:</strong> <?php echo $next ? esc_html(wp_date('d.m.Y. H:i', $next)) : 'isključena';?></span><span><strong>REST:</strong> <a href="<?php echo esc_url(rest_url(self::REST_NS . '/prices'));?>" target="_blank" rel="noopener">aktivan</a></span></div>
      <div class="ai-grid"><section class="ai-card"><h2>Postavke</h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="ai_sidrene_spremi"><?php wp_nonce_field('ai_sidrene_spremi');?><table class="form-table" role="presentation">
        <?php foreach (array('naziv' => 'Naziv webshopa','adresa' => 'Adresa objekta','vrsta_objekta' => 'Vrsta objekta','oznaka_objekta' => 'Oznaka objekta') as $k => $l) :
            ?><tr><th><label for="<?php echo esc_attr($k);?>"><?php echo esc_html($l);?></label></th><td><input type="text" id="<?php echo esc_attr($k);?>" name="<?php echo esc_attr($k);?>" value="<?php echo esc_attr($p[$k]);?>" required></td></tr><?php
        endforeach;?>
      <tr><th><label for="zadani_datum">Zadani referentni datum</label></th><td><input type="text" id="zadani_datum" name="zadani_datum" value="<?php echo esc_attr($p['zadani_datum']);?>" placeholder="10.09.2026." pattern="[0-9]{2}\.[0-9]{2}\.[0-9]{4}\." required><p class="description">Format: dd.mm.gggg. Datum sam po sebi ne popunjava cijene; koristi se kao prijedlog pri administratorskoj potvrdi.</p></td></tr>
      <tr><th><label for="brand_izvor">Izvor branda</label></th><td><input type="text" id="brand_izvor" name="brand_izvor" value="<?php echo esc_attr($p['brand_izvor']);?>"><p class="description">taxonomy:product_brand ili meta:_naziv_ključa</p></td></tr><tr><th><label for="ean_meta">EAN meta ključ</label></th><td><input type="text" id="ean_meta" name="ean_meta" value="<?php echo esc_attr($p['ean_meta']);?>"></td></tr>
      <tr><th>Automatika</th><td><label><input type="checkbox" name="auto" value="1" <?php checked($p['auto'], '1');?>> WP-Cron u 07:30</label></td></tr><tr><th>Prikaz u katalogu</th><td><label><input type="checkbox" name="katalog" value="1" <?php checked($p['katalog'], '1');?>> prikaži sidrenu cijenu u katalogu, kategorijama i pretrazi</label></td></tr><tr><th>Skrivene stavke</th><td><label><input type="checkbox" name="ukljuci_skrivene" value="1" <?php checked($p['ukljuci_skrivene'], '1');?>> uključi artikle skrivene iz WooCommerce kataloga u javni cjenik</label><p class="description">Isključeno je sigurnija zadana vrijednost. Lozinkom zaštićene stavke nikada se ne objavljuju.</p></td></tr><tr><th>Javna poveznica</th><td><label><input type="checkbox" name="footer" value="1" <?php checked($p['footer'], '1');?>> prikaži u podnožju</label></td></tr><tr><th><label for="retencija">Arhiva</label></th><td><input type="number" id="retencija" name="retencija" min="31" max="3650" value="<?php echo esc_attr($p['retencija']);?>"> dana</td></tr><tr><th>Dijagnostika</th><td><label><input type="checkbox" name="debug" value="1" <?php checked($p['debug'], '1');?>> prošireno logiranje</label></td></tr></table><?php submit_button('Spremi postavke');?></form></section>
      <div><section class="ai-card"><h2>Javni cjenici</h2><p><strong>Sve stavke:</strong> <a href="<?php echo esc_url($s['csv_url']);?>">CSV</a> · <a href="<?php echo esc_url($s['xml_url']);?>">XML</a><br><strong>Proizvodi:</strong> <a href="<?php echo esc_url($s['proizvodi_csv_url']);?>">CSV</a> · <a href="<?php echo esc_url($s['proizvodi_xml_url']);?>">XML</a><br><strong>Usluge:</strong> <a href="<?php echo esc_url($s['usluge_csv_url']);?>">CSV</a> · <a href="<?php echo esc_url($s['usluge_xml_url']);?>">XML</a><br><a href="<?php echo esc_url(get_permalink((int)get_option('ai_sidrene_stranica')));?>">Javna arhiva</a></p><p class="ai-note">CLI: <code>wp ai-sidrene generate</code><br>REST: <code>/wp-json/ai-sidrene/v1/prices</code></p></section>
      <section class="ai-card" style="margin-top:18px"><h2>1. Potvrdi postojeće cijene</h2><p>Koristite samo ako možete potvrditi da odabrana WooCommerce cijena stvarno odgovara cijeni na referentni datum. Popunjavaju se isključivo prazne sidrene cijene; postojeće vrijednosti ostaju netaknute.</p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="ai_sidrene_potvrdi_cijene"><?php wp_nonce_field('ai_sidrene_potvrdi_cijene');?><p><label>Izvor cijene<br><select name="nacin_cijene"><option value="regular">WooCommerce redovna cijena (preporučeno)</option><option value="current">Trenutno aktivna cijena (može biti akcijska)</option></select></label></p><p><label>Referentni datum<br><input type="text" name="datum_potvrde" value="<?php echo esc_attr($p['zadani_datum']);?>" placeholder="10.09.2026." pattern="[0-9]{2}\.[0-9]{2}\.[0-9]{4}\." required></label></p><p><label>Za potvrdu upišite <strong>POTVRĐUJEM</strong><br><input type="text" name="potvrda" autocomplete="off" required></label></p><button class="button button-primary" type="submit">Popuni samo prazne sidrene cijene</button></form><?php $pr = get_option('ai_sidrene_potvrda_rezultat');if (is_array($pr)) :
            ?><p class="ai-note">Popunjeno: <?php echo (int)$pr['popunjeno'];?> · Sačuvano postojećih: <?php echo (int)$pr['postojalo'];?> · Bez cijene: <?php echo (int)$pr['bez_cijene'];?></p><?php
                                                                                                                                                                                                                                                                                              endif;?></section>
      <section class="ai-card" style="margin-top:18px"><h2>2. CSV / Excel sidrene cijene</h2><p>Preuzmite predložak s proizvodima i varijacijama. Popunite <code>anchor_date</code>, <code>anchor_price</code> i <code>anchor_source</code>. Po potrebi postavite <code>item_type</code> na <code>proizvod</code> ili <code>usluga</code>, a <code>publication</code> na <code>ukljuci</code> ili <code>iskljuci</code>. Prvi prolaz može biti samo provjera.</p><p><a class="button" href="<?php echo esc_url(self::url_radnje('ai_sidrene_izvoz'));?>">Preuzmi CSV primjer</a> <a class="button" href="<?php echo esc_url(self::url_radnje('ai_sidrene_izvoz_xlsx'));?>">Preuzmi Excel primjer (.xlsx)</a></p><form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="ai_sidrene_uvoz"><?php wp_nonce_field('ai_sidrene_uvoz');?><input type="file" name="ai_csv" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required><p><label><input type="checkbox" name="commit" value="1"> Primijeni podatke; bez kvačice radi se samo provjera (dry-run)</label></p><?php submit_button('Provjeri / uvezi datoteku', 'secondary', 'submit', false);?></form><?php $ir = get_option('ai_sidrene_import_rezultat');if (is_array($ir)) :
            ?><p class="ai-note">Valjano: <?php echo (int)$ir['valid'];?> · Nevaljano: <?php echo (int)$ir['invalid'];?> · Nije pronađeno: <?php echo (int)$ir['not_found'];?> · Duplikati: <?php echo (int)($ir['duplicate'] ?? 0);?> · <?php echo $ir['commit'] ? 'primijenjeno' : 'dry-run';?></p><?php
                                                                                                                                                                                                                                                                                                                           endif;?></section>
      <?php if (current_user_can('manage_options') && current_user_can('activate_plugins')) :?>
      <section class="ai-card ai-danger-zone"><h2>3. Sigurnosna kopija i trajno uklanjanje</h2><p>Obična deinstalacija namjerno čuva poslovne zapise. Ovdje prvo možete preuzeti potpunu sigurnosnu kopiju postavki, sidrenih cijena, audit zapisa i generiranih CSV/XML cjenika.</p><p><a class="button" href="<?php echo esc_url(self::url_radnje('ai_sidrene_sigurnosna_kopija'));?>">Preuzmi potpunu sigurnosnu kopiju</a></p><hr><p><strong>Trajno brisanje nije moguće poništiti.</strong> Izbrisat će sve podatke dodatka, javnu stranicu arhive i generirane datoteke te deaktivirati dodatak.</p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="ai_sidrene_trajno_obrisi"><?php wp_nonce_field('ai_sidrene_trajno_obrisi');?><p><label for="potvrda_brisanja">Za potvrdu upišite <strong>TRAJNO OBRIŠI</strong><br><input type="text" id="potvrda_brisanja" name="potvrda_brisanja" autocomplete="off" required></label></p><?php submit_button('Trajno izbriši podatke i deaktiviraj dodatak', 'delete', 'submit', false);?></form></section>
      <?php endif;?>
      </div></div>
    <?php }
}
