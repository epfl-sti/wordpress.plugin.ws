<?php
/**
 * EPFL Memento shortcodes, by stiitweb@groupes.epfl.ch
 *
 * Usage:
 *   - [memento]
 *   - [memento tmpl=full channel=10 lang=en limit=3]
 *   - [memento tmpl=short channel=10 lang=en limit=20 category=1 title=EPFL subtitle=EPFL text=EPFL faculties=6 themes=1 publics=6]
 */

namespace EPFL\WS\Memento;
use WP_Error;

class MementoShortCode {

  /*
   * Init
   */
  function __construct() {
    add_shortcode('memento', array($this, 'wp_shortcode'));
    if ( is_admin() ) {
      add_action("admin_print_footer_scripts", array($this, 'memento_shortcode_button_script'));
    }
  }

  /*
   * Main logic
   */
  function wp_shortcode($atts, $content=null, $tag='') {
    // normalize attribute keys, lowercase
    $atts = array_change_key_case((array)$atts, CASE_LOWER);

    // override default attributes with user attributes
    $memento_atts = shortcode_atts([ 'tmpl'      => 'full', // full, short, widget
                                  'channel'   => 'sti',   // http://actu.epfl.ch/api/v1/channels/ [10 = STI, search https://actu.epfl.ch/api/v1/channels/?name=sti]
                                  'category'  => '',     // https://actu.epfl.ch/api/v1/categories/ [1: EPFL, 2: EDUCATION, 3: RESEARCH, 4: INNOVATION, 5: CAMPUS LIFE]
                                  'lang'      => 'en',   // en, fr
                                  'search'    => '',     // ??? search somewhere ???
                                  'title'     => '',     // search in title (insensitive)
                                  'subtitle'  => '',     // search in subtitle (insensitive)
                                  'text'      => '',     // search in text (insensitive)
                                  'publics'   => '',     // http://actu.epfl.ch/api/v1/publics/ [1: Prospective Students, 2: Students, 3: Collaborators, 4: Industries/partners, 5: Public, 6: Média]
                                  'themes'    => '',     // http://actu.epfl.ch/api/v1/themes/ [1: Basic Sciences, 2: Health, 3: Computer Science, 4: Engineering, 5: Environment, 6: Buildings, 7: Culture, 8: Economy, 9: Energy]
                                  'limit'     => '',     // limit of news returned
                                  'faculties' => '',     // http://actu.epfl.ch/api/v1/faculties/ [1: CDH, 2: CDM, 3: ENAC, 4: IC, 5: SB, 6: STI, 7: SV]
                                  'offset'    => '',     // specify a offset for returned news
                                ], $atts, $tag);

    $tmpl       = esc_attr($memento_atts['tmpl']);
    $channel    = esc_attr($memento_atts['channel']);
    $category   = esc_attr($memento_atts['category']);
    $lang       = esc_attr($memento_atts['lang']);
    $search     = esc_attr($memento_atts['search']);
    $title      = esc_attr($memento_atts['title']);
    $subtitle   = esc_attr($memento_atts['subtitle']);
    $text       = esc_attr($memento_atts['text']);
    $publics    = esc_attr($memento_atts['publics']);
    $themes     = esc_attr($memento_atts['themes']);
    $limit      = esc_attr($memento_atts['limit']);
    $factulties = esc_attr($memento_atts['factulties']);
    $offset     = esc_attr($memento_atts['offset']);

    // make the correct URL call

    // NOTE: as I (nbo) write this line (2017-12-29 01:58),
    // https://memento.epfl.ch/api/v1/events/ is under heavy development and it
    // seems more appropriate to get something done even if I have to use the
    // old API and that I'll need to rewrite a large part of this file later.

    // channel and lang are the 2 needed attributes, fallback to STI/EN
    $url = 'https://memento.epfl.ch/api/jahia/mementos/' . $channel . '/events/' . $lang . '/?format=json';
    if ($category)
      $url .= '&category=' . $category;
    if ($search)
      $url .= '&search=' . $search;
    if ($subtitle)
      $url .= '&subtitle=' . $subtitle;
    if ($publics)
      $url .= '&publics=' . $publics;
    if ($title)
      $url .= '&title=' . $title;
    if ($text)
      $url .= '&text=' . $text;
    if ($themes)
      $url .= '&themes=' . $themes;
    if ($limit)
      $url .= '&limit=' . $limit;
    if ($faculties)
      $url .= '&faculties=' . $faculties;
    if ($offset)
      $url .= '&offset=' . $offset;

    // Debug: print $url;

    // fetch actus items
    require_once(dirname(__FILE__) . "/inc/epfl-ws.php");
    $ws = new \EPFL\WS\epflws();
    if ( $memento_url = $ws->validate_url( $url, "memento.epfl.ch" ) ) {
      $events = $ws->get_items( $memento_url );
    } else {
      $error = new WP_Error( 'epfl-ws-memento-shortcode', 'URL not validated', 'URL: ' . $url . ' returned an error' );
      $ws->log( $error );
    }

    // Debug: $ws->debug( $events );

    switch ($tmpl) {
      default:
      case 'full':
        $display_html = $this->display_full($events);
        break;
      case 'short':
        $display_html = $this->display_short($events);
        break;
      case 'widget':
        $display_html = $this->display_widget($events);
        break;
    }
    return $display_html;
  }

  /*
   * Add the Memento button to TinyMCE
   */
  function memento_shortcode_button_script() {
    if ( is_admin() ) {
      if(wp_script_is("quicktags")) {
        ?>
          <script type="text/javascript">
            QTags.addButton(
              "memento_shortcode",
              "Memento",
              callback
            );
            var mementoDoc = '<!--\n' +
                          '= Memento short code Information =\n' +
                          'Note that more detailed information can be found on the plugin page in the administration section of your site or on GitHub.\n\n' +
                          'Memento Shortcode allows you to integrate EPFL Events (memento) in any Wordpress pages or posts. ' +
                          'To do so, just use the [memento] short code where ever you want to display the news. ' +
                          'In addition, you can be very picky on which news you want, by passing some arguments to the short code.\n' +
                          'Here are some example:\n' +
                          '\t- [memento]\n' +
                          '\t- [memento tmpl=full channel=STI lang=en limit=3]\n' +
                          '\t- [memento tmpl=short channel=STI lang=en limit=20]\n' +
                          '\n' +
                          '!!! Please be aware that this short code still relate on the old <https://memento.epfl.ch/api/jahia/mementos/>API and will switch to the <https://memento.epfl.ch/api/v1/events/>new one whenever it\'s ready. !!!' +
                          '\n' +
                          '\n' +
                          'Finally, the source and documentation of this plugin are part of EPFL-WS, you can find help and participate here: <https://github.com/epfl-sti/wordpress.plugin.ws>\n' +
                          '-->';

            function callback()
            {
              QTags.insertContent(mementoDoc);
            }
          </script>
        <?php
      }
    }
  }

  /*
   * Default template
   */
  function display_full($events)
  {
    require_once(dirname(__FILE__) . "/inc/epfl-ws.php");
    $ws = new \EPFL\WS\epflws();
    //$ws->debug($events);
    foreach ($events as $item) {
      $memento .= '<div class="memento_item" id="' . $item->id . '">';
      $memento .= '<h2>' . $item->title . '</h2>';
      $memento .= '<p><img src="' . $item->event_visual_absolute_url . '" title="' . $item->image_description . '"></p>';
      $memento .= '<p>Start date: ' . $item->event_start_date . ' ' . ($item->event_start_time ? $item->event_start_time : "") ;
      if ($item->event_end_date) {
        $memento .= ' End date: ' . $item->event_end_date . ' ' . ($item->event_end_time ? $item->event_end_time : "") ;
      }
      $memento .= '</p>';
      $memento .= '<p>' . $item->description . '</p>';
      $memento .= '<p><a href="' . $item->absolute_slug . '">Read more</a></p>';
      $memento .= '</div>';
    }
    return $memento;
  }

  /*
   * Medium sized template
   */
  function display_short($events)
  {
    foreach ($events as $item) {
      $memento .= '<div class="actu_item" id="' . $item->id . '">';
      $memento .= '<h2>' . $item->title . '</h2>';
      $memento .= '<p>' . $item->subtitle . '</p>';
      $memento .= '<img src="' . $item->visual_url . '" title="">'; // Image description + copyright not available
      // $memento .= '<a href="' . $item->absolute_slug . '">Read more</a>'; // absolute_slug not available for now
      $memento .= '</div>';
    }
    return $memento;
  }

  /*
   * Minimal template (to be used in widget)
   */
  function display_widget($events)
  {
    foreach ($events as $item) {
      $memento .= '<div class="actu_item" id="' . $item->id . '">';
      $memento .= '<h2>' . $item->title . '</h2>';
      $memento .= '<a href="' . $item->visual_url . '"><img src="' . $item->visual_url . '" title=""></a>';
      $memento .= '</div>';
    }
    return $memento;
  }

}
//add_shortcode('memento', 'EPFL\\WS\\Memento\\wp_shortcode');
new MementoShortCode();
?>
