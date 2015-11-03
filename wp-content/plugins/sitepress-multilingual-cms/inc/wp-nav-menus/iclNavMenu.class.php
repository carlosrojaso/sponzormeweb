<?php
class iclNavMenu{
    private $current_menu;
    private $current_lang;
    
    function __construct(){
        global $pagenow;
        
        add_action('init', array($this, 'init'));
                
        if(is_admin()){
            // hooks for saving menus    
            add_action('wp_create_nav_menu', array($this, 'wp_update_nav_menu'), 10, 2);
            add_action('wp_update_nav_menu', array($this, 'wp_update_nav_menu'), 10, 2);
            // hook for saving menu items
            add_action('wp_update_nav_menu_item', array($this, 'wp_update_nav_menu_item'), 10, 3);
            // filter for nav_menu_options
            add_filter('option_nav_menu_options', array($this, 'option_nav_menu_options'));
            add_action('wp_delete_nav_menu', array($this, 'wp_delete_nav_menu'));
            add_action('delete_post', array($this, 'wp_delete_nav_menu_item'));
                                    
        }
        
        // filter menus by language - also on the widgets page
        if($pagenow == 'nav-menus.php' || $pagenow == 'widgets.php' 
            || (isset($_GET['page']) && $_GET['page'] == ICL_PLUGIN_FOLDER . '/menu/languages.php')
            || isset($_POST['action']) && $_POST['action'] == 'save-widget'
            ){
            add_filter('get_terms', array($this, 'get_terms_filter'), 1, 3);        
        }
        
                
        add_filter('theme_mod_nav_menu_locations', array($this, 'theme_mod_nav_menu_locations'));
        $theme_slug = get_option( 'stylesheet' );        
        add_filter('pre_update_option_theme_mods_' . $theme_slug, array($this, 'pre_update_theme_mods_theme'));
        add_filter('wp_nav_menu_args', array($this, 'wp_nav_menu_args_filter'));
        add_filter('wp_nav_menu_items', array($this, 'wp_nav_menu_items_filter'));
        add_filter('nav_menu_meta_box_object', array($this, '_enable_sitepress_query_filters'));
    }
    
    function init(){
        global $sitepress, $sitepress_settings, $pagenow;
        
		$default_language = $sitepress->get_default_language();

        // add language controls for menus no option but javascript
        if($pagenow == 'nav-menus.php'){
            add_action('admin_footer', array($this, 'nav_menu_language_controls'), 10);
            
            wp_enqueue_script('wp_nav_menus', ICL_PLUGIN_URL . '/res/js/wp-nav-menus.js', ICL_SITEPRESS_VERSION, true);    
            wp_enqueue_style('wp_nav_menus_css', ICL_PLUGIN_URL . '/res/css/wp-nav-menus.css', array(), ICL_SITEPRESS_VERSION,'all');    
            
            // filter posts by language
            add_action('parse_query', array($this, 'parse_query'));
        }
        
        if(is_admin()){
            $this->_set_menus_language();
        }
        
        $this->get_current_menu();
        
        if(isset( $_POST['action']) && $_POST['action'] == 'menu-get-metabox'){            
            $parts = parse_url($_SERVER['HTTP_REFERER']);
            @parse_str($parts['query'], $query);
            if(isset($query['lang'])){
                $sitepress->switch_lang($query['lang']);    
            }
        }

        if ( isset( $this->current_menu[ 'language' ] )
             && isset($this->current_menu['id'])
             && $this->current_menu[ 'language' ]
             && $this->current_menu[ 'language' ] != $default_language
             && isset( $_GET[ 'menu' ] )
             && empty( $_GET[ 'lang' ] ) ) {
            wp_redirect(admin_url(sprintf('nav-menus.php?menu=%d&lang=%s',$this->current_menu['id'], $this->current_menu['language'])));    
        }
        
        if(!empty($this->current_menu['language'])){
            $this->current_lang = $this->current_menu['language'];
        }elseif(isset($_REQUEST['lang'])){
            $this->current_lang = $_REQUEST['lang'];    
        }elseif($lang = $sitepress->get_language_cookie()){
            $this->current_lang = $lang;    
        }else{
            $this->current_lang = $default_language;
        }

        if(isset($_POST['icl_wp_nav_menu_ajax'])){
            $this->ajax($_POST);
        }
        
        // for theme locations that are not translated into the current language
        // reflect status in the theme location navigation switcher
        add_action('admin_footer', array($this, '_set_custom_status_in_theme_location_switcher'));
        
        // filter menu by language when adjust ids is off
        // not on ajax calls
        if(!$sitepress_settings['auto_adjust_ids'] && !defined('DOING_AJAX')){
            add_filter('get_term', array($sitepress, 'get_term_adjust_id'));
        }     
        
        
        // Setup Menus Sync
        add_action('admin_menu', array($this, 'admin_menu_setup'));            
        if(isset($_GET['page']) && $_GET['page'] == ICL_PLUGIN_FOLDER . '/menu/menus-sync.php'){
			global $icl_menus_sync;
            include_once ICL_PLUGIN_PATH . '/inc/wp-nav-menus/menus-sync.php';
            $icl_menus_sync = new ICLMenusSync;            
        }
        
        

    }
    
    
    // Menus sync submenu
    function admin_menu_setup(){
		global $sitepress;
		if(!isset($sitepress) || !$sitepress->get_setting( 'setup_complete' )) return;

		$top_page = apply_filters('icl_menu_main_page', ICL_PLUGIN_FOLDER.'/menu/languages.php');
        add_submenu_page( $top_page, 
            __( 'WP Menus Sync', 'sitepress' ), __( 'WP Menus Sync', 'sitepress' ), 
            'wpml_manage_wp_menus_sync', ICL_PLUGIN_FOLDER . '/menu/menus-sync.php' );                        
    }
    
    
    /**
    * 
	 * Associates menus without language information with default language
	 *
    */
	private function _set_menus_language() {
        global $wpdb, $sitepress;
		$default_language = $sitepress->get_default_language();
		$untranslated_menus = $wpdb->get_col (
												"
            SELECT term_taxonomy_id
									            FROM {$wpdb->term_taxonomy} tt
									            LEFT JOIN {$wpdb->prefix}icl_translations i
									              ON CONCAT('tax_', tt.taxonomy ) = i.element_type
									                AND i.element_id = tt.term_taxonomy_id
									            WHERE tt.taxonomy='nav_menu'
									              AND i.language_code IS NULL"
		);
		foreach ( (array) $untranslated_menus as $item ) {
                $sitepress->set_element_language_details($item, 'tax_nav_menu', null, $default_language );
            }
		$untranslated_menu_items = $wpdb->get_col (
													"
										            SELECT p.ID
										            FROM {$wpdb->posts} p
										            LEFT JOIN {$wpdb->prefix}icl_translations i
										              ON CONCAT('post_', p.post_type )  = i.element_type
										                AND i.element_id = p.ID
										            WHERE p.post_type = 'nav_menu_item'
										              AND i.language_code IS NULL"
		);
        if(!empty($untranslated_menu_items)){
            foreach($untranslated_menu_items as $item){
                $sitepress->set_element_language_details($item, 'post_nav_menu_item', null, $default_language );
            }
        }
    }
    
    function ajax($data){
        if($data['icl_wp_nav_menu_ajax'] == 'translation_of'){
            $trid = isset($data['trid']) ? $data['trid'] : false;
            $this->_render_translation_of($data['lang'], $trid);
        }
        exit;
    }
    
    function _get_menu_language($menu_id){
        global $sitepress, $wpdb;
        $menu_tt_id = $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy='nav_menu'",$menu_id));
        $lang = $sitepress->get_element_language_details($menu_tt_id, 'tax_nav_menu');
        return $lang;
    }
    
    /**
    * gets first menu in a specific language
    * used to override nav_menu_recently_edited when a different language is selected
    * @param $lang
    * @return int
    */
    function _get_first_menu($lang){
        global $wpdb;
        $menu_tt_id = $wpdb->get_var("SELECT MIN(element_id) FROM {$wpdb->prefix}icl_translations WHERE element_type='tax_nav_menu' AND language_code='".esc_sql($lang)."'");    
        $menu_id = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id=%d",$menu_tt_id));
        return (int) $menu_id;
    }
    
    function get_current_menu(){
        global $sitepress;
        $nav_menu_recently_edited = get_user_option( 'nav_menu_recently_edited' );        
        $nav_menu_recently_edited_lang = $this->_get_menu_language($nav_menu_recently_edited);
		$current_language = $sitepress->get_current_language();
		$admin_language_cookie = $sitepress->get_language_cookie();
		if( !isset( $_REQUEST['menu'] ) &&
                isset($nav_menu_recently_edited_lang->language_code) && 
                $nav_menu_recently_edited_lang->language_code != $current_language
		){
            // if no menu is specified and the language is set override nav_menu_recently_edited
            $nav_menu_selected_id = $this->_get_first_menu( $current_language );
            if($nav_menu_selected_id){
                update_user_option(get_current_user_id(), 'nav_menu_recently_edited', $nav_menu_selected_id);    
            }else{
                $_REQUEST['menu'] = 0;
            }
            
        }elseif( !isset( $_REQUEST['menu'] ) && !isset($_GET['lang']) 
                && (empty($nav_menu_recently_edited_lang) || $nav_menu_recently_edited_lang != $admin_language_cookie )
                && (empty($_POST['action']) || $_POST['action']!='update')){
            // if no menu is specified, no language is set, override nav_menu_recently_edited if its language is different than default           
            $nav_menu_selected_id = $this->_get_first_menu( $current_language );
            update_user_option(get_current_user_id(), 'nav_menu_recently_edited', $nav_menu_selected_id);
        }elseif(isset( $_REQUEST['menu'] )){
            $nav_menu_selected_id = $_REQUEST['menu'];
        }else{
            $nav_menu_selected_id = $nav_menu_recently_edited;            
        }
        
        $this->current_menu['id'] = $nav_menu_selected_id;        
        if($this->current_menu['id']){
            $this->_load_menu($this->current_menu['id']);
        }else{
            $this->current_menu['trid'] = isset($_GET['trid']) ? intval($_GET['trid']) : null;
            if(isset($_POST['icl_nav_menu_language'])){
                $this->current_menu['language'] = $_POST['icl_nav_menu_language'];    
            }elseif(isset($_GET['lang'])){
                $this->current_menu['language'] = $_GET['lang'];    
            }else{
                $this->current_menu['language'] = $admin_language_cookie;
            }            
            $this->current_menu['translations'] = array();
        }    
    }
    
    function _load_menu($menu_id = false){
        global $sitepress, $wpdb;

        $menu_id                        = $menu_id ? $menu_id : $this->current_menu[ 'id' ];
        $menu_tt_id = $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy='nav_menu'",$menu_id));
        $this->current_menu['trid'] = $sitepress->get_element_trid($menu_tt_id, 'tax_nav_menu');        
        
        if($this->current_menu['trid']){
            $this->current_menu['translations'] = $sitepress->get_element_translations($this->current_menu['trid'], 'tax_nav_menu');    
        }else{
            $this->current_menu['translations'] = array();
        }
        
        foreach($this->current_menu['translations'] as $tr){
            if($menu_tt_id == $tr->element_id){
                $this->current_menu['language'] = $tr->language_code;                    
            }
        }
    }
    
    function wp_update_nav_menu($menu_id, $menu_data = null){
        global $sitepress, $wpdb;
        if($menu_data){
            if(isset($_POST['icl_translation_of']) && $_POST['icl_translation_of']){
				$src_term_id = $_POST['icl_translation_of'];
				if ($src_term_id != 'none') {
					$trid = $sitepress->get_element_trid($_POST['icl_translation_of'], 'tax_nav_menu');
				} else {
					$trid = null;
				}
            }else{
                $trid = isset($_POST['icl_nav_menu_trid']) ? intval($_POST['icl_nav_menu_trid']) : null;                 
            }        
            $language_code = isset($_POST['icl_nav_menu_language']) ? $_POST['icl_nav_menu_language'] : $sitepress->get_default_language(); 
            $menu_id_tt = $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy='nav_menu'",$menu_id));
            $sitepress->set_element_language_details($menu_id_tt, 'tax_nav_menu', $trid, $language_code);
        }
        $this->current_menu['id'] = $menu_id;
        $this->_load_menu($this->current_menu['id']);
    }
    
    function wp_delete_nav_menu($id){
        global $wpdb;
        $menu_id_tt = $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy='nav_menu'",$id));
        $q = "DELETE FROM {$wpdb->prefix}icl_translations WHERE element_id=%d AND element_type='tax_nav_menu' LIMIT 1";
				$q_prepared = $wpdb->prepare($q, $menu_id_tt);
				$wpdb->query($q_prepared);
    }
    
    function wp_update_nav_menu_item($menu_id, $menu_item_db_id, $args){
        // TBD
        // TBD
        global $sitepress;
        $trid = $sitepress->get_element_trid($menu_item_db_id, 'post_nav_menu_item');
        $language_code = $this->current_lang;
        $sitepress->set_element_language_details($menu_item_db_id, 'post_nav_menu_item', $trid, $language_code);
    }

    function wp_delete_nav_menu_item($menu_item_id){
        global $wpdb;
        $post = get_post($menu_item_id);
        if(!empty($post->post_type) && $post->post_type == 'nav_menu_item'){
						$q = "DELETE FROM {$wpdb->prefix}icl_translations WHERE element_id=%d AND element_type='post_nav_menu_item' LIMIT 1";
						$q_prepared = $wpdb->prepare($q, $menu_item_id);
            $wpdb->query($q_prepared);
        }
    }

    function nav_menu_language_controls(){
        global $sitepress, $wpdb;
		$default_language = $sitepress->get_default_language();
		if($this->current_menu['language'] != $default_language ){
            $menus_wout_translation = $this->get_menus_without_translation($this->current_menu['language']);    
        }
        if(isset($this->current_menu['translations'][ $default_language ])){
            $menus_wout_translation['0'] = (object)array(
                'element_id'=>$this->current_menu['translations'][ $default_language ]->element_id,
                'trid'      =>'0',
                'name'      =>$this->current_menu['translations'][ $default_language ]->name
                );
        }
        
        $langsel = '<br class="clear" />';    
        
        // show translations links if this is not a new element              
        if($this->current_menu['id']){
            $langsel .= '<div class="howto icl_nav_menu_text" style="float:right;">';    
            $langsel .= __('Translations:', 'sitepress');                
            foreach($sitepress->get_active_languages() as $lang){            
                if($lang['code'] == $this->current_menu['language']) continue;
                if(isset($this->current_menu['translations'][$lang['code']])){
                    $lang_suff = '&lang=' . $lang['code'];
                    $menu_id = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id=%d",$this->current_menu['translations'][$lang['code']]->element_id));
                    $tr_link = '<a style="text-decoration:none" title="'. esc_attr(__('edit translation', 'sitepress')).'" href="'.admin_url('nav-menus.php').
                        '?menu='.$menu_id. $lang_suff .'">'.
                        $lang['display_name'] . '&nbsp;<img src="'.ICL_PLUGIN_URL.'/res/img/edit_translation.png" alt="'. esc_attr(__('edit', 'sitepress')).
                        '" width="12" height="12" /></a>';
                }else{
                    $tr_link = '<a style="text-decoration:none" title="'. esc_attr(__('add translation', 'sitepress')).'" href="'.admin_url('nav-menus.php').
                        '?action=edit&menu=0&trid='.$this->current_menu['trid'].'&lang='.$lang['code'].'">'. 
                        $lang['display_name'] . '&nbsp;<img src="'.ICL_PLUGIN_URL.'/res/img/add_translation.png" alt="'. esc_attr(__('add', 'sitepress')).
                        '" width="12" height="12" /></a>';
                }
                $trs[] = $tr_link ;
            }
            $langsel .= '&nbsp;';
						if (isset($trs)) {
							$langsel .= join (', ', $trs);
						}
            $langsel .= '</div><br />';    
            $langsel .= '<div class="howto icl_nav_menu_text" style="float:right;">';    
            $langsel .= '<div><a href="'.admin_url('admin.php?page=' . ICL_PLUGIN_FOLDER . '/menu/menus-sync.php').'">' . __('Synchronize menus between languages.', 'sitepress') . '</a></div>';    
            $langsel .= '</div>';    
            
        }
        
        // show languages dropdown                
        $langsel .= '<label class="menu-name-label howto"><span>' . __('Language', 'sitepress') . '</span>';
        $langsel .= '&nbsp;&nbsp;';          
        $langsel .= '<select name="icl_nav_menu_language" id="icl_menu_language">';    
        foreach($sitepress->get_active_languages() as $lang){
            if(isset($this->current_menu['translations'][$lang['code']]) && $this->current_menu['language'] != $lang['code']) continue;            
            if($this->current_menu['language']){
                $selected = $lang['code'] == $this->current_menu['language'] ? ' selected="selected"' : '';    
            }else{
                $selected = $lang['code'] == $sitepress->get_current_language() ? ' selected="selected"' : '';
            }
            $langsel .= '<option value="' . $lang['code'] . '"' . $selected . '>' . $lang['display_name'] . '</option>';    
        }
        $langsel .= '</select>';
        $langsel .= '</label>';  
        
        // show 'translation of' if this element is not in the default language and there are untranslated elements
        $langsel .= '<span id="icl_translation_of_wrap">';
        
        if ( isset( $this->current_menu[ 'language' ] )
             && isset($this->current_menu['id'])
             && $this->current_menu[ 'id' ]
             && $this->current_menu[ 'language' ] != $default_language && ! empty( $menus_wout_translation )
        ) {
            $langsel .= '<label class="menu-name-label howto"><span>' . __('Translation of:', 'sitepress') . '</span>';                
            if(!$this->current_menu['id'] && isset($_GET['trid'])){
                $disabled = ' disabled="disabled"';
            }else{
                $disabled = '';
            }
            $langsel .= '<select name="icl_translation_of" id="icl_menu_translation_of"'.$disabled.'>';    
            $langsel .= '<option value="none">--' . __('none', 'sitepress') . '--</option>';
            foreach($menus_wout_translation as $mtrid=>$m){
                if($this->current_menu['trid'] === $mtrid || (isset($this->current_menu['translations'][ $default_language ]) && $this->current_menu['translations'][ $default_language ]->element_id)){
                    $selected = ' selected="selected"';
                }else{
                    $selected = '';
                }
                $langsel .= '<option value="' . $m->element_id . '"' . $selected . '>' . $m->name . '</option>';    
            }
            $langsel .= '</select>';
            $langsel .= '</label>';
        }
        $langsel .= '</span>';
        
        // add trid to form
        if($this->current_menu['trid']){
            $langsel .= '<input type="hidden" id="icl_nav_menu_trid" name="icl_nav_menu_trid" value="' . $this->current_menu['trid'] . '" />';
        }
        
        $langsel .= '';
        ?>
        <script type="text/javascript">
        addLoadEvent(function(){
            jQuery('#update-nav-menu .publishing-action:first').before('<?php echo addslashes($langsel); ?>');
            jQuery('#side-sortables').before('<?php $this->languages_menu() ?>');
            <?php if($this->current_lang != $default_language): echo "\n"; ?>
            jQuery('.nav-tabs .nav-tab').each(function(){
                jQuery(this).attr('href', jQuery(this).attr('href')+'&lang=<?php echo $this->current_lang ?>');
            });        
	        var update_menu_form = jQuery('#update-nav-menu');
	        var original_action = update_menu_form.attr('ACTION') ? update_menu_form.attr('ACTION') : '';
	        update_menu_form.attr('ACTION', original_action+'?lang=<?php echo $this->current_lang ?>');
            <?php endif; ?>            
        });
        </script>
        <?php            
    }
    
    function get_menus_without_translation($lang){
        global $sitepress, $wpdb;
				$res_query = "
            SELECT ts.element_id, ts.trid, t.name 
            FROM {$wpdb->prefix}icl_translations ts
            JOIN {$wpdb->term_taxonomy} tx ON ts.element_id = tx.term_taxonomy_id
            JOIN {$wpdb->terms} t ON tx.term_id = t.term_id
            WHERE ts.element_type='tax_nav_menu' 
                AND ts.language_code=%s
                AND tx.taxonomy = 'nav_menu'
        ";
				$default_language = $sitepress->get_default_language();
				$res_query_prepared = $wpdb->prepare($res_query, $default_language);
        $res = $wpdb->get_results($res_query_prepared);
        $menus = array();
        foreach($res as $row){            
            if(!$wpdb->get_var( $wpdb->prepare("SELECT translation_id
                                                FROM {$wpdb->prefix}icl_translations
                                                WHERE trid=%d
                                                AND language_code=%s", $row->trid ,$lang ) )
            ){
                $menus[$row->trid] = $row;
            }
        }       
        return $menus;
    }
    
    function _render_translation_of($lang, $trid = false){
        global $sitepress;
        $out = '';
        
        if($sitepress->get_default_language() != $lang){
            $menus = $this->get_menus_without_translation($lang);        
            $out .= '<label class="menu-name-label howto"><span>' . __('Translation of:', 'sitepress') . '</span>';                
            $out .= '<select name="icl_translation_of" id="icl_menu_translation_of">';    
            $out .= '<option value="">--' . __('none', 'sitepress') . '--</option>';                
            foreach($menus as $mtrid=>$m){
                if(intval($trid) === $mtrid){
                    $selected = ' selected="selected"';
                }else{
                    $selected = '';
                }
                $out .= '<option value="' . $m->element_id . '"' . $selected . '>' . $m->name . '</option>';    
            }
            $out .= '</select>';
            $out .= '</label>';
        }
                
        echo $out;
    }
    
    function get_menus_by_language(){
        global $wpdb, $sitepress;
        $langs = array();
				$res_query = "
            SELECT lt.name AS language_name, l.code AS lang, COUNT(ts.translation_id) AS c
            FROM {$wpdb->prefix}icl_languages l
                JOIN {$wpdb->prefix}icl_languages_translations lt ON lt.language_code = l.code
                JOIN {$wpdb->prefix}icl_translations ts ON l.code = ts.language_code            
            WHERE lt.display_language_code=%s
                AND l.active = 1
                AND ts.element_type = 'tax_nav_menu'
            GROUP BY ts.language_code
            ORDER BY major DESC, english_name ASC
        ";
				$admin_language = $sitepress->get_admin_language();
				$res_query_prepared = $wpdb->prepare($res_query, $admin_language);
        $res = $wpdb->get_results($res_query_prepared);
        foreach($res as $row){
            $langs[$row->lang] = $row;
        }        
        return $langs;
    }
    
    function languages_menu($echo = true){
        global $sitepress;
        $langs = $this->get_menus_by_language();
        
        // include empty languages
        foreach($sitepress->get_active_languages() as $lang){
            if(!isset($langs[$lang['code']])){
                $langs[$lang['code']] = new stdClass();
                $langs[$lang['code']]->language_name = $lang['display_name'];
                $langs[$lang['code']]->lang = $lang['code'];
            }            
        }
        $url = admin_url('nav-menus.php');
        $ls = array();
        foreach($langs as $l){
            $class = $l->lang == $this->current_lang ? ' class="current"' : '';
            $urlsuff = '?lang=' . $l->lang;
            $ls[ ] = '<a href="' . $url . $urlsuff . '"' . $class . '>' . esc_html( $l->language_name ) . '</a>';
        }
        $ls_string = '<div class="icl_lang_menu icl_nav_menu_text">';
        $ls_string .= join('&nbsp;|&nbsp;', $ls);
        $ls_string .= '</div>';
        if($echo){
            echo $ls_string;
        }else{
            return $ls_string;
        }
    }
    
    function get_terms_filter($terms, $taxonomies, $args){
        global $wpdb, $sitepress, $pagenow;        
        // deal with the case of not translated taxonomies
        // we'll assume that it's called as just a single item
        if(!$sitepress->is_translated_taxonomy($taxonomies[0]) && 'nav_menu' != $taxonomies[0]){
            return $terms;
        }      
        
        // special case for determining list of menus for updating auto-add option
        if($taxonomies[0] == 'nav_menu' && $args['fields'] == 'ids' && $_POST['action'] == 'update' && $pagenow=='nav-menus.php'){
            return $terms;
        }
          
        if(!empty($terms)){
            $txs = array();
            foreach($taxonomies as $t){
                $txs[] = 'tax_' . $t;
            }
            $el_types = wpml_prepare_in( $txs );
            
            // get all term_taxonomy_id's
            $tt = array();
            foreach($terms as $t){
                if(is_object($t)){
                    $tt[] = $t->term_taxonomy_id;    
                }else{
                    if(is_numeric($t)){
                        $tt[] = $t;    
                    }
                }
            }
            
            // filter the ones in the current language
            $ftt = array();
            if(!empty($tt)){
                $ftt = $wpdb->get_col(
                        $wpdb->prepare("
                            SELECT element_id
                            FROM {$wpdb->prefix}icl_translations
                            WHERE element_type IN ({$el_types})
                              AND element_id IN (" . wpml_prepare_in($tt, '%d') . ")
                              AND language_code=%s", $this->current_lang )
                );
            }

            foreach($terms as $k=>$v){
                if(isset($v->term_taxonomy_id) && !in_array($v->term_taxonomy_id, $ftt)){
                    unset($terms[$k]);
                }
            }
        }                
        return  array_values($terms);        
    }
    
    // filter posts by language    
    function parse_query($q){
        global $sitepress;
        // not filtering nav_menu_item
        if(isset($q->query_vars['post_type']) && $q->query_vars['post_type'] === 'nav_menu_item'){
            return $q;
        } 
        
        // also - not filtering custom posts that are not translated
        if(isset($q->query_vars['post_type']) && $sitepress->is_translated_post_type($q->query_vars['post_type'])){
            $q->query_vars['suppress_filters'] = 0;
        }
        
        return $q;
    }
        
    function theme_mod_nav_menu_locations($val){        
        global $sitepress;
        if($sitepress->get_default_language() != $this->current_lang){
            if(!empty($val)){
                foreach($val as $k=>$v){
                    $val[$k] = icl_object_id($val[$k], 'nav_menu', true, $this->current_lang);       
                }
            }
        }
        return $val;
    }
    
    function pre_update_theme_mods_theme($val){
        global $sitepress;
		$default_language = $sitepress->get_default_language();
        if(isset($val['nav_menu_locations'])){
            foreach((array)$val['nav_menu_locations'] as $k=>$v){
				if( $this->current_lang != $default_language ){
                    $val['nav_menu_locations'][$k] = icl_object_id($v, 'nav_menu',true, $default_language );
                }            
            }        
        }

        return $val;
    }
    
    function option_nav_menu_options($val){
        global $wpdb, $sitepress;
        // special case of getting menus with auto-add only in a specific language
		$debug_backtrace = $sitepress->get_backtrace( 5 ); //Ignore objects and limit to first 5 stack frames, since 4 is the highest index we use
        if(isset($debug_backtrace[4]) && $debug_backtrace[4]['function'] == '_wp_auto_add_pages_to_menu' && !empty($val['auto_add'])){
            $post_lang = isset($_POST['icl_post_language']) ? $_POST['icl_post_language'] : (isset($_POST['lang']) ? $_POST['lang'] : false);

			//$val['auto_add'] = false;
			if($post_lang) {
				$val['auto_add'] = $wpdb->get_col($wpdb->prepare("
					SELECT element_id
					FROM {$wpdb->prefix}icl_translations
					WHERE element_type='tax_nav_menu'
						AND element_id IN (" . wpml_prepare_in($val['auto_add'], '%d') . ")
						AND language_code = %s", $post_lang ) );
			}
        }

        return $val;
    }

	function wp_nav_menu_args_filter( $args ) {

		if ( ! $args[ 'menu' ] ) {
			$locations = get_nav_menu_locations();
			if ( isset( $args[ 'theme_location' ] ) && isset( $locations[ $args[ 'theme_location' ] ] ) ) {
				$args[ 'menu' ] = icl_object_id( $locations[ $args[ 'theme_location' ] ], 'nav_menu' );
			}
		};

		if ( ! $args[ 'menu' ] ) {
			remove_filter( 'theme_mod_nav_menu_locations', array( $this, 'theme_mod_nav_menu_locations' ) );
			$locations = get_nav_menu_locations();
			if ( isset( $args[ 'theme_location' ] ) && isset( $locations[ $args[ 'theme_location' ] ] ) ) {
				$args[ 'menu' ] = icl_object_id( $locations[ $args[ 'theme_location' ] ], 'nav_menu' );
			}
			add_filter( 'theme_mod_nav_menu_locations', array( $this, 'theme_mod_nav_menu_locations' ) );
		}

		// $args[ "menu" ] can be an object consequently to widget's call
		if ( is_object($args[ 'menu' ]) && ( ! empty( $args[ 'menu' ]->term_id )) ) {
				$args['menu'] = wp_get_nav_menu_object(icl_object_id($args['menu']->term_id, 'nav_menu'));
		}

		if ( ( ! is_object ( $args['menu'] )) && is_numeric ( $args['menu'] ) ) {
				$args[ 'menu' ] = wp_get_nav_menu_object( icl_object_id( $args[ 'menu' ], 'nav_menu' ) );
		}

		if ( ( ! is_object ( $args['menu'] )) && is_string ( $args["menu"] ) ) {
            $term = get_term_by( 'slug', $args[ 'menu' ], 'nav_menu' );
            if ( false === $term) {
                    $term = get_term_by( 'name', $args[ 'menu' ], 'nav_menu' );
            }

            if ( false !== $term ) {
                    $args['menu'] = wp_get_nav_menu_object(icl_object_id($term->term_id, 'nav_menu'));
            }
		}

		if ( ! is_object ( $args['menu'] ) ) {
				$args['menu'] = false;
		}

		return $args;
	}
    
    function wp_nav_menu_items_filter($items){
        $items = preg_replace(
            '|<li id="([^"]+)" class="menu-item menu-item-type-taxonomy"><a href="([^"]+)">([^@]+) @([^<]+)</a>|', 
            '<li id="$1" class="menu-item menu-item-type-taxonomy"><a href="$2">$3</a>', $items);
        return $items;
    }
    
    function _set_custom_status_in_theme_location_switcher(){
        global $sitepress, $wpdb;
        
        if(defined('ICL_IS_WPML_RESET') && ICL_IS_WPML_RESET) return;
        
        $tl = (array)get_theme_mod('nav_menu_locations');
        $menus_not_translated = array();
        foreach($tl as $k=>$menu){
            $menu_tt_id = $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy='nav_menu'",$menu));
            $menu_trid = $sitepress->get_element_trid($menu_tt_id, 'tax_nav_menu');
            $menu_translations = $sitepress->get_element_translations($menu_trid, 'tax_nav_menu');
            if(!isset($menu_translations[$this->current_lang]) || !$menu_translations[$this->current_lang]){
                $menus_not_translated[] = $k;                
            }
        }
        if(!empty($menus_not_translated)){
            ?>
            <script type="text/javascript">
            addLoadEvent(function(){
                <?php foreach($menus_not_translated as $menu_id): ?>
	            var menu_id = '<?php echo $menu_id?>';
	            var location_menu_id = jQuery('#locations-' + menu_id);
	            if(location_menu_id.length > 0){
                    location_menu_id.find('option').first().html('<?php echo esc_js(__('not translated in current language','sitepress')) ?>');
                    location_menu_id.css('font-style','italic');
                    location_menu_id.change(function(){if(jQuery(this).val()!=0) jQuery(this).css('font-style','normal');else jQuery(this).css('font-style','italic')});
                }
                <?php endforeach; ?>
            });            
            </script>
            <?php             
        }
    }
    
    // on the nav menus when selecting pages using the pagination filter pages > 2 by language    
    function _enable_sitepress_query_filters($args){
        if(isset($args->_default_query)){
            $args->_default_query['suppress_filters'] = false;    
        }
        return $args;
    }
}
?>
