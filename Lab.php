<?php

/**
 * Model and controller for an EPFL lab, research group or administrative unit
 */

namespace EPFL\WS\Labs;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

use \Error;

require_once(__DIR__ . "/inc/ldap.inc");
use \EPFL\WS\LDAPClient;

require_once(__DIR__ . "/inc/base-classes.inc");
use \EPFL\WS\Base\UniqueKeyTypedPost;
use \EPFL\WS\Base\CustomPostTypeController;

require_once(__DIR__ . "/inc/auto-fields.inc");
use \EPFL\WS\AutoFields;
use \EPFL\WS\AutoFieldsController;


require_once(__DIR__ . "/inc/i18n.inc");
use function \EPFL\WS\___;
use function \EPFL\WS\__x;

require_once(__DIR__ . "/Person.php");
use \EPFL\WS\Persons\Person;

require_once(__DIR__ . "/OrganizationalUnit.php");
use \EPFL\WS\OrganizationalUnits\OrganizationalUnit;

class LabNotFoundException extends \Exception { }
class LabUnicityException extends \Exception { }


/**
 * Model class for Labs
 *
 * Labs represented in WordPress as a custom post type.
 */
class Lab extends UniqueKeyTypedPost
{
    const SLUG = "epfl-lab";

    const WEBSITE_URL_META        = "epfl_lab_website_url";
    const UNIQUE_ID_META          = "epfl_unique_id";
    const DN_META                 = "epfl_dn";
    const OU_META                 = "epfl_ou";
    const LAB_DESCRIPTION_FR_META = "epfl_lab_description_fr";
    const LAB_DESCRIPTION_EN_META = "epfl_lab_description_en";
    const LAB_MANAGER_META        = "epfl_lab_manager";
    const MEMBER_COUNT_META       = "epfl_lab_member_count";
    const LAB_POSTAL_ADDRESS_META = "epfl_lab_postal_address";
    // Set this to a truthy value to make ->is_real() return false:
    const NOT_A_LAB_META          = "not_a_lab";

    static function get_post_type ()
    {
        return self::SLUG;
    }

    static function get_or_create_by_abbrev ($abbrev)
    {
        $unit_entries = LDAPClient::query_by_unit_name($abbrev);
        if (!($unit_entries && count($unit_entries))) {
            throw new LabNotFoundException(sprintf("Unknown lab abbrev %s", $abbrev));
        }
        return static::get_or_create_by_ldap_entry($unit_entries[0]);
    }

    static function get_or_create_by_ldap_entry ($entry)
    {
        $uid = $entry["uniqueidentifier"][0];
        if (! $uid) {
            throw new Error("get_or_create_by_ldap_entry(): bad entry: " . var_export($entry, true));
        }
        $that = static::_get_or_create(array(
            self::UNIQUE_ID_META => $uid
        ));
        $that->_ldap_result = $entry;
        return $that;
    }

    static function get_by_unique_id ($unique_id)
    {
        return static::_get_by_unique_key(array(
            self::UNIQUE_ID_META => $unique_id
        ));
    }

    /**
     * This is for shortcodes - DO NOT use this in deeply embedded code;
     * the abbrev is NOT a good unique ID for labs.
     */
    static function get_by_abbrev ($abbrev)
    {
        $unit_entries = LDAPClient::query_by_unit_name($abbrev);
        if (count($unit_entries) != 1) return null;
        return static::get_by_unique_id($unit_entries[0]["uniqueidentifier"][0]);
    }

    public static function find_all_by_dn_suffix ($dn_suffix)
    {
        $query = new \WP_Query(array(
            'post_type' => self::get_post_type(),
            'posts_per_page' => -1,
            'meta_query' => array(array(
                'key'     => self::DN_META,
                'compare' => 'RLIKE',
                'value'   => '.*,' . $dn_suffix
            )),
            'order'       => 'asc',
            'orderby'     => 'meta_value',
            'meta_key'    => self::OU_META
        ));
        $thisclass = get_called_class();
        return array_map(function($result) use ($thisclass) {
            return $thisclass::get($result);
        }, $query->get_posts());
    }

    public function sync ()
    {
        $ldap_result = $this->_get_ldap_result();
        $meta_input = array(
            self::UNIQUE_ID_META          => $this->get_unique_id(),
            self::WEBSITE_URL_META        => explode(" ", $ldap_result["labeleduri"][0])[0],
            self::OU_META                 => $ldap_result["ou"][0],
            self::DN_META                 => $ldap_result["dn"],
            self::LAB_DESCRIPTION_FR_META => $ldap_result["description"][0],
            self::LAB_DESCRIPTION_EN_META => $ldap_result["description;lang-en"][0],
            self::LAB_MANAGER_META        => $ldap_result["unitmanager"][0],
            self::LAB_POSTAL_ADDRESS_META => $ldap_result["postaladdress"][0],
        );

        $members = LDAPClient::query_people_in_unit($ldap_result["dn"]);
        $meta_input[self::MEMBER_COUNT_META] = count($members);

        wp_update_post(array(
            "ID"            => $this->ID,
            "post_type"     => $this->get_post_type(),
            "post_title"    => $ldap_result["description;lang-en"][0],
            "meta_input"    => $meta_input
        ));
        AutoFields::of(get_called_class())->append(array_keys($meta_input));

        $more_meta = apply_filters('epfl_lab_additional_meta', array(), $this);
        if ($more_meta) {
            $this->_update_meta($more_meta);
        }
    }

    private function _update_meta($meta_array)
    {
        $auto_fields = AutoFields::of(get_called_class());
        foreach ($meta_array as $k => $v) {
            update_post_meta($this->ID, $k, $v);
            $auto_fields->append(array($k));
        }
    }

    public function _get_ldap_result ()
    {
        if (! $this->_ldap_result) {
            $unit_entries = LDAPClient::query_by_unit_unique_id(
                $this->get_unique_id());
            if ((! $unit_entries) || (0 === count($unit_entries))) {
                throw new LabNotFoundException(sprintf("Unknown unique identifier %d",
                                                       $this->get_unique_id()));
            } else if (1 !== count($unit_entries)) {
                throw new LabUnicityException(sprintf(
                    "Found %d results for lab's (supposedly) uniqueIdentifier %d",
                    count($unit_entries), $this->get_unique_id()));
            } else {
                $this->_ldap_result = $unit_entries[0];
            }
        }
        return $this->_ldap_result;
    }

    public function get_name ()
    {
        return get_the_title($this->ID);
    }

    public function get_abbrev ()
    {
        return get_post_meta($this->ID, self::OU_META, true);
    }

    public function get_description ($lang='en')
    {
        return ($lang == 'fr') ? get_post_meta($this->ID, self::LAB_DESCRIPTION_FR_META, true) :
                                 get_post_meta($this->ID, self::LAB_DESCRIPTION_EN_META, true);
    }

    public function get_website_url ()
    {
        return get_post_meta($this->ID, self::WEBSITE_URL_META, true);
    }

    public function get_unique_id ()
    {
        return get_post_meta($this->ID, self::UNIQUE_ID_META, true);
    }

    public function get_lab_manager ()
    {
        $sciper = get_post_meta($this->ID, self::LAB_MANAGER_META, true);
        return Person::find_by_sciper($sciper);
    }

    public function get_dn ()
    {
        return get_post_meta($this->ID, self::DN_META, true);
    }

    public function get_organizational_unit ()
    {
        return OrganizationalUnit::of_lab($this);
    }

    public function get_postaladdress ()
    {
        return get_post_meta($this->ID, self::LAB_POSTAL_ADDRESS_META, true);
    }

    public function get_member_count ()
    {
        $count_txt = get_post_meta($this->ID, self::MEMBER_COUNT_META, true);
        if (is_string($count_txt) && (strlen($count_txt) > 0)) {
            return $count_txt + 0;
        } else {
            return null;
        }
    }

    public function is_active ()
    {
        return ($this->get_member_count() !== 0);
    }

    public function is_real ()
    {
        return ($this->is_active() &&
                ! get_post_meta($this->ID, self::NOT_A_LAB_META, true));
    }
}

class LabController extends CustomPostTypeController
{
    static function get_model_class ()
    {
        return Lab::class;
    }

    static function hook ()
    {
        add_action('init', array(get_called_class(), 'register_post_type'));

        static::auto_fields_controller()->hook();
        static::add_thumbnail_column();
        static::call_sync_on_save();
        static::strikethrough_inactive_labs();

        // Both supplemental columns use the default render_%s_column methods
        static::column('abbrev')
              ->set_title(___('Lab abbreviation'))
              ->hook_after('title');
        static::column('member_count')
              ->set_title(___('Members'))
              ->hook_after('abbrev');
    }

    /**
     * Make it so that labs exist.
     *
     * Under WordPress, almost everything publishable is a post. register_post_type() is
     * invoked to create a particular flavor of posts that describe labs.
     */
    static function register_post_type ()
    {
        register_post_type(
            Lab::get_post_type(),
            array(
                'labels'             => array(
                    'name'               => __x( 'Labs', 'post type general name' ),
                    'singular_name'      => __x( 'Lab', 'post type singular name' ),
                    'menu_name'          => __x( 'EPFL Labs', 'admin menu' ),
                    'name_admin_bar'     => __x( 'Lab', 'add new on admin bar' ),
                    'add_new'            => __x( 'Add New', 'add new lab' ),
                    'add_new_item'       => ___( 'Add New Lab' ),
                    'new_item'           => ___( 'New Lab' ),
                    'edit_item'          => ___( 'Edit Lab' ),
                    'view_item'          => ___( 'View Lab' ),
                    'all_items'          => ___( 'All Labs' ),
                    'search_items'       => ___( 'Search Lab' ),
                    'not_found'          => ___( 'No labs found.' ),
                    'not_found_in_trash' => ___( 'No labs found in Trash.' )
                ),
                'description'        => ___( 'EPFL labs and research groups' ),
                'public'             => true,
                'publicly_queryable' => true,
                'show_ui'            => true,
                'show_in_menu'       => true,
                'menu_position'      => 42,
                'query_var'          => true,
                'capability_type'    => 'post',
                'has_archive'        => true,
                'hierarchical'       => false,
                'taxonomies'         => array('category', 'post_tag'),
                'menu_icon'          => 'dashicons-lightbulb',
                'supports'           => array( 'editor', 'thumbnail', 'custom-fields' ),
                'register_meta_box_cb' => array(get_called_class(), 'add_meta_boxes')
            ));
    }

    /**
     * Called to add meta boxes on an "edit" page in wp-admin.
     */
    static function add_meta_boxes ()
    {
        static::auto_fields_controller()->add_meta_boxes();
    }

    private static function auto_fields_controller ()
    {
        return new AutoFieldsController(Lab::class);
    }

    static function render_abbrev_column ($lab)
    {
        $abbrev = $lab->get_abbrev();
        if ($url = $lab->get_website_url()) {
            echo sprintf('<a href="%s">%s</a>', $url, $abbrev);
        } else {
            echo $abbrev;
        }
    }

    static function render_member_count_column ($lab)
    {
        // TODO: we could link to a suitable search query in EPFL People
        echo $lab->get_member_count();
    }

    static $_css_strikethrough_inactive_labs_sent;
    /**
     * Arrange for the wp-admin list to show inactive labs stricken through
     */
    static function strikethrough_inactive_labs ()
    {
        add_filter('post_class', function($classes, $class, $post_id) {
            if (! is_admin()) { return; }
            if (! ($lab = Lab::get($post_id))) { return; }
            if ($lab->is_active()) { return; }
            array_push($classes, "lab-inactive");
            return $classes;
        }, 10, 3);

        if (self::$_css_strikethrough_inactive_labs_sent) { return; }
        $_css_strikethrough_inactive_labs_sent = true;
        add_action('admin_head', function() {
            ?>
<style>
tr.lab-inactive {
    text-decoration: line-through;
}
</style>
<?php
        });
    }
}

LabController::hook();
