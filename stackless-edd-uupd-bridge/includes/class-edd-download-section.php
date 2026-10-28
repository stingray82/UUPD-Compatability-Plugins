<?php
/** Native EDD Download Details editor tab, EDD 3.3.6+. */
defined('ABSPATH') || exit;

final class Stackless_EDD_UUPD_Download_Section extends \EDD\Admin\Downloads\Editor\Section {
    protected $id = 'stackless-uupd';
    protected $priority = 35;
    protected $icon = 'update';

    public function get_label() {
        return __('UUPD & Releases', 'stackless-edd-uupd-bridge');
    }

    public function render() {
        $id = isset($this->item->ID) ? absint($this->item->ID) : 0;
        $post = $id ? get_post($id) : null;
        if (!$post || $post->post_type !== 'download' || !current_user_can('edit_post', $id)) return;
        Stackless_EDD_UUPD_Bridge::panel($post);
    }
}
