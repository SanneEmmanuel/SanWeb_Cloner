<?php
/**
 * Plugin Name: SanWeb Cloner
 * Description: Clones content from a public webpage into a WordPress page. For academic use only.
 * Version: 1.2
 * Author: Dr. Sanne Karibo
 */

if (!defined('ABSPATH')) exit;

// Add admin menu
add_action('admin_menu', function () {
    add_menu_page(
        'SanWeb Cloner',
        'SanWeb Cloner',
        'manage_options',
        'sanweb-cloner',
        'sanweb_cloner_page',
        'dashicons-admin-site-alt',
        90
    );
});

// Admin page UI
function sanweb_cloner_page() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized user');

    $agreed = get_option('sanweb_cloner_agreed', false);

    if (!$agreed && isset($_POST['sanweb_agree'])) {
        update_option('sanweb_cloner_agreed', true);
        $agreed = true;
    }

    echo '<div class="wrap">';
    echo '<h1>SanWeb Cloner</h1>';

    if (!$agreed) {
        echo '<h2>Terms and Conditions</h2>';
        echo '<p>This tool is strictly for academic purposes. Do not use it to duplicate copyrighted websites. Ensure you have rights to clone and republish the content.</p>';
        echo '<form method="post"><input type="submit" name="sanweb_agree" class="button button-primary" value="Agree and Continue"></form>';
    } else {
        ?>
        <form method="post">
            <label for="sanweb_url"><strong>Enter the URL of the webpage to clone:</strong></label><br><br>
            <input type="url" name="sanweb_url" id="sanweb_url" required style="width: 50%;" placeholder="https://example.com" /><br><br>
            <label><input type="checkbox" name="save_images" value="1"> Save images into WordPress</label><br>
            <label><input type="checkbox" name="link_images" value="1"> Use image link in site</label><br><br>
            <?php submit_button('Clone and Create Page'); ?>
        </form>
        <?php

        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['sanweb_url'])) {
            $url = esc_url_raw($_POST['sanweb_url']);
            $save_images = isset($_POST['save_images']);
            $link_images = isset($_POST['link_images']);

            $response = wp_remote_get($url);
            if (is_wp_error($response)) {
                echo '<div class="notice notice-error"><p>Error fetching the URL.</p></div>';
                return;
            }

            $html = wp_remote_retrieve_body($response);
            libxml_use_internal_errors(true);
            $doc = new DOMDocument();
            @$doc->loadHTML($html);
            libxml_clear_errors();

            $titleNodes = $doc->getElementsByTagName('title');
            $site_name = ($titleNodes->length > 0) ? $titleNodes->item(0)->textContent : 'Cloned Page';

            $body = $doc->getElementsByTagName('body')->item(0);
            $content = $body ? sanweb_extract_content($body, $save_images, $link_images) : '';

            $page_title = 'SanWeb-clone (' . wp_strip_all_tags($site_name) . ')';

            $new_page_id = wp_insert_post([
                'post_title' => sanitize_text_field($page_title),
                'post_content' => wp_kses_post($content),
                'post_status' => 'draft',
                'post_type' => 'page'
            ]);

            if ($new_page_id) {
                echo '<div class="notice notice-success" style="padding: 20px; font-size: 16px; background-color: #d7fddc; border-left: 5px solid #2ecc71;"><span style="font-size: 20px;">✅</span> <strong>Site Saved!</strong> Your cloned page is created as a draft. <a href="' . esc_url(get_edit_post_link($new_page_id)) . '">Edit Page</a></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to create the page.</p></div>';
            }
        }
    }

    echo '</div>';
}

// Extract content with optional image handling
function sanweb_extract_content($node, $save_images, $link_images) {
    $content = '';
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE) {
            $content .= $child->textContent;
        } elseif ($child->nodeType === XML_ELEMENT_NODE) {
            $tag = strtolower($child->nodeName);
            if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'p', 'li', 'strong', 'em'])) {
                $content .= '<' . $tag . '>' . sanweb_extract_content($child, $save_images, $link_images) . '</' . $tag . '>';
            } elseif (in_array($tag, ['ul', 'ol'])) {
                $content .= '<' . $tag . '>' . sanweb_extract_content($child, $save_images, $link_images) . '</' . $tag . '>';
            } elseif ($tag === 'img') {
                $src = $child->getAttribute('src');
                if ($save_images && $src) {
                    $media_id = media_sideload_image($src, 0, null, 'src');
                    if (!is_wp_error($media_id)) {
                        $content .= '<img src="' . esc_url($media_id) . '" alt="" />';
                    }
                } elseif ($link_images && $src) {
                    $content .= '<img src="' . esc_url($src) . '" alt="" />';
                }
            } else {
                $content .= sanweb_extract_content($child, $save_images, $link_images);
            }
        }
    }
    return $content;
}
