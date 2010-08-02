<?php
/*
Plugin Name: YAWPP
Plugin URI: http://github.com/feuerfuchs/YAWPP
Description: Yet Another Wordpress Podcasting Plugin
Author: feuerfuchs
Version: 0.1
*/

class YAWPP
{
    
    private $feedImage;
    private $itunesMeta;
    private $audioPlayerTpl;
    private $videoPlayerTpl;
    private $playerHead;
    private $episodeNrTpl;
    
    public function __construct() 
    {
        if(!get_option('yawpp_feedimg') || !get_option('yawpp_itunesmeta') || !get_option('yawpp_playertpl') || !get_option('yawpp_playerhead')) {
            $this->setOpt();
        }
        
        $this->getOpt();
        
        // we need to attach some actions
        //uhm... yeah. do not touch the RSS<2 Feed. :-)
        //add_action('rss_head', array($this, 'rssHead'));
        add_action('rss2_head', array($this, 'rss2Head'));
        add_action('rss2_ns', array($this, 'rss2Ns'));
        add_action('rss2_item', array($this, 'itemMeta'));
        add_action('atom_head', array($this, 'atomHead')); 
        add_action('admin_menu', array($this, 'adminMenu'));
        add_action('the_content', array($this, 'replaceTags'));
        add_action('wp_head', array($this, 'headScripts'));
        add_action('save_post', array($this, 'savePostdata'), 10, 2);


        add_filter('the_title', array($this, 'postTitle'), 10, 2);
        
        
        add_action('admin_print_scripts', array($this, 'adminScripts'));
        add_action('admin_menu', array($this, 'addMetaBox'));
        add_action('yawpp_enclose', array($this, 'doEnclose'));
        
        require_once 'yawpp_podcasts.php';
    }
    
    function postTitle($title, $post)
    {
        if(is_int($post)) {
            $post_id = $post;
        } elseif(!empty($post->ID)) {
            $post_id = $post->ID;
        } else {
            return $title;
        }
        // episode number available?
        if($episode_id = get_post_meta($post_id, 'yawpp_episode_id', true)) {
            $title = $this->episodeNumberPattern($episode_id) . $title;
        }
        
        return $title;
    }
    
    private function episodeNumberPattern($episode_id)
    {
        return str_replace('%%nr%%', $episode_id, $this->episodeNrTpl);
    }
    
    function savePostdata($post_id, $post)
    {
        // verify this came from the our screen and with proper authorization,
        // because save_post can be triggered at other times
        if (!wp_verify_nonce($_POST['yawpp_nonce'], plugin_basename(__FILE__))) {
            return $post_id;
        }

        // verify if this is an auto save routine. If it is our form has not been submitted, so we dont want
        // to do anything
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return $post_id;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return $post_id;
        }
        
        if ($post->post_type != 'post') {
            return;
        }

        
        $podcast_url = $_POST['yawpp_file'];
        $episode_id  = $_POST['yawpp_episode_id'];
        
        // no file attached? -- do nothing
        if(empty($podcast_url)) {
            return $post_id;
        }
        
        // add our metadata
        $func = 'update_post_meta';
        if(!get_post_meta($post_id, 'yawpp_episode_id')) {
            $func = 'add_post_meta';
        }
        
        add_post_meta($post_id, '_yawpp_enclose', $podcast_url);
        add_post_meta($post_id, '_yawpp_func', $func);
        $func($post_id, 'yawpp_episode_id', $episode_id);
        
        wp_schedule_single_event(time(), 'yawpp_enclose');
    }
    
    function doEnclose()
    {
        global $wpdb;
        
        // Do Enclosures
        while ($meta = $wpdb->get_row("SELECT * FROM {$wpdb->postmeta} WHERE {$wpdb->postmeta}.meta_key = '_yawpp_enclose' LIMIT 1")) {
            do_action('delete_postmeta', $meta->meta_id);
            $wpdb->query( $wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_id =  %d", $meta->meta_id) );
            do_action('deleted_postmeta', $meta->meta_id);
            
            $func = get_post_meta($meta->post_id, '_yawpp_func');
            
            // delete all other enclosures in this post
            delete_post_meta($meta->post_id, 'enclosure');
            
            // delete some yawpp metadata
            delete_post_meta($meta->post_id, '_yawpp_func');
            
            // ...and our type 
            delete_post_meta($meta->post_id, 'yawpp_type');
            
            $url = $meta->meta_value;
            
            if($headers = wp_get_http_headers($url)) {
                $len = (int) $headers['content-length'];
                $contentType = $headers['content-type'];
                $type = substr( $contentType, 0, strpos( $contentType, "/" ));
                $allowed_types = array( 'video', 'audio' );
                if(in_array($type, $allowed_types)) {
                    $meta_value = "$url\n$len\n$contentType\n";
                    $wpdb->insert($wpdb->postmeta, array('post_id' => $meta->post_id, 'meta_key' => 'enclosure', 'meta_value' => $meta_value) );
                    do_action( 'added_postmeta', $wpdb->insert_id, $meta->post_id, 'enclosure', $meta_value );
                    
                    add_post_meta($meta->post_id, 'yawpp_type', $type);
                }
            }
        }
    }
        
    function addMetaBox() 
    {
        add_meta_box('yawppPostBox', 'YAWPP - Podcast', array($this, 'postBox'), 'post', 'normal');
    }
    
    function adminScripts()
    {
        wp_register_script('yawpp-admin', site_url(PLUGINDIR.'/yawpp/yawpp_admin.js'), array('jquery','media-upload','thickbox'));#
        wp_enqueue_script('yawpp-admin');
    }
        
    function postBox()
    {
        global $post;

        $episode_id = get_post_meta($post->ID, 'yawpp_episode_id', true);
        $enclosure = get_post_meta($post->ID, 'enclosure', true);
        list($file, $size, $mime) = explode("\n", $enclosure);
        
        // wp-nonce
        echo '<input type="hidden" name="yawpp_nonce" id="yawpp_nonce" value="' . 
        wp_create_nonce( plugin_basename(__FILE__) ) . '" />';

        // the file selector
        echo '<p><label for="yawpp_file">' . __("File", 'yawpp' ) . '</label> ';
        echo '<input type="text" name="yawpp_file" id="yawpp_file" value="', (empty($file)?'':htmlspecialchars($file)), '" size="25" />&nbsp;';
        echo '<a class="thickbox" href="media-upload.php?post_id=', $post->ID, '&amp;type=audio&amp;TB_iframe=true" id="yawpp_upload_audio"><img alt="Audio" src="images/media-button-music.gif"/></a>&nbsp;&nbsp;';
        echo '<a class="thickbox" href="media-upload.php?post_id=', $post->ID, '&amp;type=video&amp;TB_iframe=true" id="yawpp_upload_video"><img alt="Video" src="images/media-button-video.gif"/></a>';
        if(!empty($size)) {
            echo '&nbsp;', __('Size'), '&nbsp;', $this->toMegabytes($size), '&nbsp;MiB&nbsp;';
        }
        echo '</p>';
        
        // episode id
        echo '<p><label for="yawpp_episode_id">' . __("Episode Number", 'yawpp' ) . '</label> ';
        echo '<input type="text" name="yawpp_episode_id" id="yawpp_episode_id" value="', (empty($episode_id)?'':htmlspecialchars($episode_id)), '" size="25" /></p>';
    }

    
    public function audioBtn($form_fields, $post)
    {
        // Only add the extra button if the attachment is an mp3 file
        if ($post->post_mime_type == 'audio/mpeg') {
                $file = wp_get_attachment_url($post->ID);
                $form_fields["url"]["html"] .= '<button type="button" class="button" id="audio-player-'. $post->ID . '" title="[audio:' . attribute_escape($file) . ']">Audio-Player</button>';
                $form_fields["url"]["html"] .= '<script type="text/javascript">
                jQuery(document).ready(function(){
                jQuery("#audio-player-' . $post->ID . '").bind("click", function(){var win = window.dialogArguments || opener || parent || top;win.send_to_editor("[audio:' . attribute_escape($file) . ']")});});</script>';
        }
        
        if ($post->post_mime_type == 'video/mp4') {
                $file = wp_get_attachment_url($post->ID);
                $form_fields["url"]["html"] .= '<button type="button" class="button" id="audio-player-'. $post->ID . '" title="[video:' . attribute_escape($file) . ']">Video-Player</button>';
                $form_fields["url"]["html"] .= '<script type="text/javascript">
                jQuery(document).ready(function(){
                jQuery("#audio-player-' . $post->ID . '").bind("click", function(){var win = window.dialogArguments || opener || parent || top;win.send_to_editor("[video:' . attribute_escape($file) . ']")});});</script>';
        }
        
        return $form_fields;
    }
    
    public function itemMeta()
    {
        if(!empty($this->itunesMeta['author'])) {
            echo '<itunes:author>', htmlspecialchars($this->itunesMeta['author']), '</itunes:author>';
        }
    }
    
    public function rss2Ns()
    {
        echo 'xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"';
    }
    
    private function getOpt()
    {
        $this->feedImage = get_option('yawpp_feedimg');
        
        $this->itunesMeta = get_option('yawpp_itunesmeta');
        
        $this->audioPlayerTpl = get_option('yawpp_playertpl');
        $this->videoPlayerTpl = get_option('yawpp_videoplayertpl');
        
        $this->episodeNrTpl = get_option('yawpp_episode_nr_tpl');
        
        $this->playerHead = get_option('yawpp_playerhead');
    }

    private function setOpt()
    {
        $func = 'update_option';
        if(!get_option('yawpp_feedimg') || !get_option('yawpp_itunesmeta') || !get_option('yawpp_playertpl') || !get_option('yawpp_playerhead') || !get_option('yawpp_episode_nr_tpl')) {
            // set all options to default
            $this->defaultOpt();
            $func = 'add_option';
        }
        
        $func('yawpp_feedimg', $this->feedImage);
        $func('yawpp_itunesmeta', $this->itunesMeta);
        $func('yawpp_playertpl', $this->audioPlayerTpl);
        $func('yawpp_videoplayertpl', $this->videoPlayerTpl);
        $func('yawpp_playerhead', $this->playerHead);
        $func('yawpp_episode_nr_tpl', $this->episodeNrTpl);
    }
    
    private function defaultOpt()
    {
        $this->feedImage = array(
            'enabled' => 0,
            'url'     => '/your-feed-image.png',
            'height'  => '32',
            'width'   => '32'
        );
        
        $this->itunesMeta = array(
            'subtitle'  => 'Your Podcats subtitle',
            'summary'   => 'A short summary.',
            'image'     => 'http://your-blog.example.com/your-itunes-image.png',
            'author'    => 'Your Name',
            'ownerName' => 'Your Name',
            'ownerMail' => 'you@example.com',
            'explicit'  => 0,
            'mainCat'   => 'Society & Culture',
            'subCat'    => 'Personal Journals'
        );
        
        $this->audioPlayerTpl = '<p><a href="%%url%%">Download Audio File</a></p>';
        $this->videoPlayerTpl = '<p><a href="%%url%%">Download Video File</a></p>';
        $this->playerHead = '<!-- add your header scripts here -->';
        
        $this->episodeNrTpl = 'Podcast[%%nr%%] ';
    }
    
    public function adminMenu()
    {
        add_options_page('YAWPP Konfiguration', 'YAWPP', 8, __FILE__, array($this, 'adminInterface'));
    }
    
    public function adminInterface()
    {
        if(!empty($_POST['setdefault'])) {
            $this->defaultOpt();
            $this->setOpt();
            echo '<div id="message" class="updated" style="background-color: rgb(255, 251, 204);"><p>YAWPP läuft nun auf Standardeinstellungen.</p></div>';
        }
        
        if(!empty($_POST['saveopt'])) {
            $this->feedImage = array(
                'enabled' => $this->p('imgenable'),
                'url'     => $this->p('imgurl'),
                'height'  => $this->p('imgheight'),
                'width'   => $this->p('imgwidth')
            );
        
            $this->itunesMeta = array(
                'subtitle'  => $this->p('meta-subtitle'),
                'summary'   => $this->p('meta-summary'),
                'image'     => $this->p('meta-image'),
                'author'    => $this->p('meta-author'),
                'ownerName' => $this->p('meta-ownerName'),
                'ownerMail' => $this->p('meta-ownerMail'),
                'explicit'  => $this->p('meta-explicit'),
                'mainCat'   => $this->p('meta-mainCat'),
                'subCat'    => $this->p('meta-subCat')
            );
            
            $this->audioPlayerTpl = $this->p('aptpl');
            $this->videoPlayerTpl = $this->p('vptpl');
            $this->playerHead = $this->p('aphead');
            $this->episodeNrTpl = $this->p('episodenrtpl');
            
            $this->setOpt();
            echo '<div id="message" class="updated" style="background-color: rgb(255, 251, 204);"><p>Die neuen Einstellungen wurden gespeichert.</p></div>';
        }
        echo '<div class="wrap">
<h2>YAWPP Konfiguration</h2>
<form action="" method="post" class="style">
<h3>Audio-Player</h3>
<table class="form-table">
<tr valign="top">
<th scope="row"><label for="vptpl">Videoplayer-Template:</label></th>
<td><textarea cols="50" rows="10" class="large-text code" name="vptpl" id="vptpl">', htmlspecialchars($this->videoPlayerTpl), '</textarea></td>
</tr>
<tr valign="top">
<th scope="row"><label for="aptpl">Audioplayer-Template:</label></th>
<td><textarea cols="50" rows="10" class="large-text code" name="aptpl" id="aptpl">', htmlspecialchars($this->audioPlayerTpl), '</textarea></td>
</tr>
<tr valign="top">
<td colspan="2">
<p><tt>%%eurl%%</tt> wird durch die urlencodete URL zur Audiodatei,<br /> <tt>%%url%%</tt> durch die URL zur Audiodatei,<br /> <tt>%%id%%</tt> durch eine eindeutige Player-ID,<br /> <tt>%%fsize%%</tt> durch einen String, der die Dateigröße in MiB angibt, ersetzt.</p>
</td>
</tr>
<tr valign="top">
<th scope="row"><label for="aphead">Player-Header:</label></th>
<td><textarea cols="50" rows="10" class="large-text code" name="aphead" id="aphead">', htmlspecialchars($this->playerHead), '</textarea></td>
</tr>
<tr valign="top">
<td colspan="2">
<p>Dieser HTML-Code wird in den <tt>&lt;head&gt;</tt>-Bereich des ausgegebenen HTML eingefügt, um z.B. zusätzliche Javascripts für den Player einbinden zu können.</p>
</td>
</tr>
</table>
<h3>Diverses</h3>
<table class="form-table">
<tr valign="top">
<th scope="row"><label for="episodenrtpl">Muster für Episoden-Nummer im Post-Titel</label></th>
<td><input class="regular-text" type="text" name="episodenrtpl" id="episodenrtpl" value="', htmlspecialchars($this->episodeNrTpl), '" /></td>
</tr>
</table>
<h3>Feed</h3>
<h4>Bild</h4>
<table class="form-table">
<tr valign="top">
<th scope="row"><label for="imgenable">Bild in den Feed einbinden</label></th>
<td><input type="checkbox" name="imgenable" id="imgenable" value="1"', ($this->feedImage['enabled'] ? ' checked="checked" ' : ''),' /></td>
</tr>
<tr valign="top">
<th scope="row"><label for="imgurl">Bild-URL:</label></th>
<td><input  class="regular-text" type="text" name="imgurl" value="', htmlspecialchars($this->feedImage['url']), '" id="imgurl" /></td>
</tr>
<tr valign="top">
<th scope="row"><label for="imgheight">Bild-Höhe:</label></th>
<td><input class="regular-text" value="', htmlspecialchars($this->feedImage['height']), '" type="text" name="imgheight" id="imgheight" /></td>
</tr>
<tr valign="top">
<th scope="row"><label for="imgheight">Bild-Breite:</label></th>
<td><input class="regular-text" value="', htmlspecialchars($this->feedImage['width']), '" type="text" name="imgwidth" id="imgwidth" /></td>
</tr>
</table>
<h4>iTunes-Metadaten</h4>
<p>Ja, ich weiß, es sieht hässlich aus, aber es funktioniert. Ätsch.</p>
<table class="form-table">';
        foreach($this->itunesMeta as $name => $val) {
            $name = htmlspecialchars($name);
            $val = htmlspecialchars($val);
            echo '<tr valign="top">
        <th scope="row"><label for="meta-', $name, '">', $name, ':</label></th>
        <td><input class="regular-text" type="text" name="meta-', $name, '" id="meta-', $name, '" value="', $val, '" /></td>
        </tr>';
        }
        echo '</table>
            <p class="submit"><input type="submit" name="saveopt" class="button-primary" /></p>
            <p class="submit"><input type="submit" name="setdefault" value="Standardeinstellungen wiederherstellen" /></p>
        </form></div>';
    }
    
    public function rssHead()
    {
        $this->rssImage();
        
        $this->itunesMeta();
    }
    
    public function rss2Head()
    {
        $this->rssImage();
        
        $this->itunesMeta();
    }
    
    private function rssImage()
    {
        if($this->feedImage['enabled']) {
            echo '<image>
    <url>', htmlspecialchars($this->feedImage['url']), '</url>
    <title>', htmlspecialchars(get_bloginfo('name')), '</title>
    <link>', htmlspecialchars(get_bloginfo('url')), '</link>
    <width>', $this->feedImage['width'], '</width>
    <height>', $this->feedImage['height'], '</height>
</image>';
        }
    }
    
    public function atomHead()
    {
        if($this->feedImage['enabled']) {
            echo '<logo>', htmlspecialchars($this->feedImage['url']), '</logo>';
        }
        
        // no iTunes information here!
        //$this->itunesMeta();
    }
    
    private function itunesMeta()
    {
        echo '<!-- iTunes Metadata -->';
        if(!empty($this->itunesMeta['subtitle'])) {
            echo '<itunes:subtitle>', htmlspecialchars($this->itunesMeta['subtitle']), '</itunes:subtitle>';
        }
        if(!empty($this->itunesMeta['summary'])) {
            echo '<itunes:summary>', htmlspecialchars($this->itunesMeta['summary']), '</itunes:summary>';
        }
        if(!empty($this->itunesMeta['image'])) {
            echo '<itunes:image href="', htmlspecialchars($this->itunesMeta['image']), '" />';
        }
        if(!empty($this->itunesMeta['author'])) {
            echo '<itunes:author>', htmlspecialchars($this->itunesMeta['author']), '</itunes:author>';
        }
        if(!empty($this->itunesMeta['ownerName']) && !empty($this->itunesMeta['ownerMail'])) {
            echo '<itunes:owner>
    <itunes:name>', htmlspecialchars($this->itunesMeta['ownerName']), '</itunes:name>
    <itunes:email>', htmlspecialchars($this->itunesMeta['ownerMail']), '</itunes:email>
</itunes:owner>';
        }
        if($this->itunesMeta['explicit']) {
            echo '<itunes:explicit>yes</itunes:explicit>';
        } else {
            echo '<itunes:explicit>no</itunes:explicit>';
        }
        
        if(!empty($this->itunesMeta['mainCat']) && !empty($this->itunesMeta['subCat'])) {
            echo '<itunes:category text="', htmlspecialchars($this->itunesMeta['mainCat']), '">
    <itunes:category text="', htmlspecialchars($this->itunesMeta['subCat']), '" />
</itunes:category>';
        }
    }
    
    private function toMegabytes($bytes)
    {
        return number_format($bytes / 1024 / 1024, 2, ',', '.');
    }
    
    private function playerCode($url, $tpl, $fsize)
    {
        static $playerId = 1;
        
        $eurl = urlencode($url);
        $finfo = pathinfo($url);
        $fileSize = $this->toMegabytes($fsize);
        $search = array(
            '%%eurl%%',
            '%%url%%',
            '%%id%%',
            '%%fsize%%',
            '%%ftype%%'
        );
        $replace = array(
            $eurl,
            $url,
            $playerId,
            $fileSize,
            strtoupper($finfo['extension'])
        );
        
        $playerId++;
        return str_replace($search, $replace, $tpl);
    }
    
    public function replaceTags($str = '')
    {
        global $post; // ugly... but required.

        $str = $this->autoPlayer($str, $post);
        
        return $str;
    }
    
    private function getPlayerTpl($mime)
    {
        $type = substr($mime, 0, strpos($mime, '/'));
        
        switch($type) {
            case 'audio':
                return $this->audioPlayerTpl;
                break;
            case 'video':
                return $this->videoPlayerTpl;
                break;
        }
    }
    
    public function autoPlayer($str, $post)
    {
        $audio = get_post_meta($post->ID, 'enclosure');
        
        if(!is_array($audio)) return $str;
        
        foreach($audio as $a) {
            $enclosure = explode("\n", $a);
            $str .= $this->playerCode(trim($enclosure[0]), $this->getPlayerTpl(trim($enclosure[2])), trim($enclosure[1]));
        }
        
        return $str;
    }
    
    public function headScripts()
    {
        echo $this->playerHead;
    }
    
    private function p($var)
    {
        // wordpress seems to escape all variables - fuck yeah!
        return stripslashes($_POST[$var]);
    }
    
    public function returnFalse()
    {
        return false;
    }
        
}

$yawpp = new YAWPP();

