// assets/js/gp-uupd-meta-sync.js
jQuery(function ($) {

    /**
     * VERSION SYNC
     * Our field:      #wpi_item_version
     * License field:  #getpaid_license_manager_item_version
     */
    (function () {
        var $ours  = $('#wpi_item_version');
        var $their = $('#getpaid_license_manager_item_version');

        if (!$ours.length || !$their.length) {
            return;
        }

        // Initial sync – if only one has a value, copy to the other.
        if ($their.val() && !$ours.val()) {
            $ours.val($their.val());
        } else if ($ours.val() && !$their.val()) {
            $their.val($ours.val());
        }

        var syncing = false;

        function sync(src, dest) {
            if (syncing) {
                return;
            }
            syncing = true;
            dest.val(src.val());
            syncing = false;
        }

        $ours.on('input change', function () {
            sync($ours, $their);
        });

        $their.on('input change', function () {
            sync($their, $ours);
        });
    })();

    /**
     * CHANGELOG SYNC (WYSIWYG <-> WYSIWYG)
     *
     * Our editor:       ID: wpi_changelog_editor, textarea: #wpi_changelog_editor
     * License editor:   ID: getpaid_license_manager_changelog, textarea: #getpaid_license_manager_changelog
     */
    (function () {
        var ourEditorId    = 'wpi_changelog_editor';
        var theirEditorId  = 'getpaid_license_manager_changelog';

        // ✅ Correct textarea IDs
        var $ourTextarea   = $('#wpi_changelog_editor');
        var $theirTextarea = $('#getpaid_license_manager_changelog');

        if (!$ourTextarea.length || !$theirTextarea.length) {
            return;
        }

        function getEditorContent(id, $fallback) {
            if (window.tinymce && tinymce.get(id)) {
                return tinymce.get(id).getContent();
            }
            return $fallback ? $fallback.val() : '';
        }

        function setEditorContent(id, $fallback, value) {
            if ($fallback) {
                $fallback.val(value);
            }
            if (window.tinymce && tinymce.get(id)) {
                var ed = tinymce.get(id);
                if (ed && ed.getContent() !== value) {
                    ed.setContent(value);
                }
            }
        }

        var syncingChangelog = false;

        function syncFrom(idSrc, $srcFallback, idDest, $destFallback) {
            if (syncingChangelog) {
                return;
            }
            syncingChangelog = true;
            var value = getEditorContent(idSrc, $srcFallback);
            setEditorContent(idDest, $destFallback, value);
            syncingChangelog = false;
        }

        // Initial sync – prefer License Manager content if present.
        var theirsInitial = getEditorContent(theirEditorId, $theirTextarea);
        var oursInitial   = getEditorContent(ourEditorId, $ourTextarea);

        if (theirsInitial && !oursInitial) {
            setEditorContent(ourEditorId, $ourTextarea, theirsInitial);
        } else if (oursInitial && !theirsInitial) {
            setEditorContent(theirEditorId, $theirTextarea, oursInitial);
        }

        function bindEditor(id, $fallback, otherId, $otherFallback) {
            if (window.tinymce && tinymce.get(id)) {
                tinymce.get(id).on('keyup change', function () {
                    syncFrom(id, $fallback, otherId, $otherFallback);
                });
            }
        }

        // Bind when TinyMCE editors are initialised.
        $(document).on('tinymce-editor-init', function (event, editor) {
            if (editor.id === ourEditorId) {
                bindEditor(ourEditorId, $ourTextarea, theirEditorId, $theirTextarea);
            }
            if (editor.id === theirEditorId) {
                bindEditor(theirEditorId, $theirTextarea, ourEditorId, $ourTextarea);
            }
        });

        // If editors are already initialised before our handler runs.
        if (window.tinymce) {
            if (tinymce.get(ourEditorId)) {
                bindEditor(ourEditorId, $ourTextarea, theirEditorId, $theirTextarea);
            }
            if (tinymce.get(theirEditorId)) {
                bindEditor(theirEditorId, $theirTextarea, ourEditorId, $ourTextarea);
            }
        }

        // Extra safety: sync if user types in the underlying textareas (HTML mode)
        $ourTextarea.on('input change', function () {
            syncFrom(ourEditorId, $ourTextarea, theirEditorId, $theirTextarea);
        });
        $theirTextarea.on('input change', function () {
            syncFrom(theirEditorId, $theirTextarea, ourEditorId, $ourTextarea);
        });
    })();

});
