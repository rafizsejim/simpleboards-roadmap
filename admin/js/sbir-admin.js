/**
 * SimpleBoards Admin JavaScript
 */

(function($) {
    'use strict';

    function t(key, fallback) {
        if (window.sbir_admin && window.sbir_admin.i18n && window.sbir_admin.i18n[key]) {
            return window.sbir_admin.i18n[key];
        }
        return fallback;
    }

    function createStatusImpactNotice(data) {
        var $notice = $('<div/>', {
            'class': 'notice notice-warning inline sbir-status-impact'
        }).css('margin-top', '8px');

        var message = (data && data.message) ? String(data.message) : '';
        if (message) {
            $notice.append($('<p/>').text(message));
        }

        if (data && Array.isArray(data.items) && data.items.length) {
            var $list = $('<ul/>').css('margin-top', '6px');
            data.items.forEach(function(it) {
                var title = (it && it.title) ? String(it.title) : '';
                var href = (it && it.edit_link) ? String(it.edit_link) : '#';
                var $link = $('<a/>', { href: href }).text(title);
                $list.append($('<li/>').append($link));
            });
            $notice.append($list);
        }

        return $notice;
    }

    $(document).ready(function() {
        // Live impact notice when changing Status board assignment
        $(document).on('change', '#sbir_status_board', function(){
            var targetBoardId = $(this).val();
            var termId = $('#sbir_status_term_id').val();
            if (!termId) { return; }
            $('.sbir-status-impact').remove();
            $.ajax({
                url: sbir_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'sbir_status_reassign_impact',
                    nonce: sbir_admin.nonce,
                    term_id: termId,
                    target_board_id: targetBoardId
                },
                success: function(res){
                    if (res && res.success && res.data && res.data.count > 0) {
                        var $row = $('#sbir_status_board').closest('td');
                        $row.append(createStatusImpactNotice(res.data));
                    }
                }
            });
        });
        
        // Enable/disable Status select based on Item Type selection
        function sbirUpdateStatusEnable() {
            var isRoadmap = $('input[name="sbir_is_roadmap"]:checked').val() === 'yes';
            var $select = $('#sbir_status_select');
            $select.prop('disabled', !isRoadmap);
        }
        $(document).on('change', 'input[name="sbir_is_roadmap"]', sbirUpdateStatusEnable);
        sbirUpdateStatusEnable();

        // Filter statuses when Board changes (and on load)
        function sbirFilterStatusesByBoard() {
            var $board = $('#sbir_board_id');
            var $select = $('#sbir_status_select');
            var statuses = [];
            try {
                statuses = JSON.parse($select.attr('data-statuses') || '[]');
            } catch (e) {
                statuses = [];
            }
            var boardId = parseInt($board.val(), 10) || 0;
            var current = parseInt($select.val(), 10) || 0;
            // Rebuild options
            var opts = ['<option value="">' + t('select_status', 'Select Status') + '</option>'];
            statuses.forEach(function(s){
                // Show globals (board 0) and those matching current board
                if (boardId ? (s.board === 0 || s.board === boardId) : (s.board === 0)) {
                    var sel = (s.id === current) ? ' selected' : '';
                    opts.push('<option value="' + s.id + '"' + sel + '>' + s.name + '</option>');
                }
            });
            $select.html(opts.join(''));
        }
        $(document).on('change', '#sbir_board_id', sbirFilterStatusesByBoard);
        sbirFilterStatusesByBoard();

        // Bulk edit: keep status field meaningful for selected type.
        function sbirSyncBulkEditStatusState() {
            var $bulkRow = $('tr.inline-edit-row.bulk-edit-row');
            if (!$bulkRow.length) {
                return;
            }
            var $type = $bulkRow.find('select[name="sbir_bulk_type"]');
            var $status = $bulkRow.find('select[name="sbir_bulk_status_term"]');
            if (!$type.length || !$status.length) {
                return;
            }
            var typeValue = $type.val();
            var disabled = typeValue === 'idea';
            $status.prop('disabled', disabled);
            if (disabled) {
                $status.val('__clear');
            }
        }

        $(document).on('change', 'tr.inline-edit-row.bulk-edit-row select[name="sbir_bulk_type"]', sbirSyncBulkEditStatusState);
        $(document).on('click', '#bulk_edit', function() {
            setTimeout(sbirSyncBulkEditStatusState, 0);
        });

        // Board Setup: show Default tab only when Ideas tab is enabled.
        function sbirToggleBoardDefaultTabField() {
            var enabled = $('#sbir_enable_ideas').is(':checked');
            $('#sbir-default-tab-field, #sbir-default-tab-field-desc').toggle(!!enabled);
        }
        $(document).on('change', '#sbir_enable_ideas', sbirToggleBoardDefaultTabField);
        sbirToggleBoardDefaultTabField();
        
    });

})(jQuery);