/**
 * SimpleBoards Public JavaScript - Optimized with Performance Enhancements
 */

(function($) {
    'use strict';

    function t(key, fallback) {
        if (window.sbir_public && window.sbir_public.i18n && window.sbir_public.i18n[key]) {
            return window.sbir_public.i18n[key];
        }
        return fallback;
    }

    function getFocusableElements($container) {
        if (!$container || !$container.length) {
            return $();
        }
        return $container
            .find('a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])')
            .filter(':visible');
    }

    function trapFocusIn($container, event) {
        var $focusables = getFocusableElements($container);
        if (!$focusables.length) {
            return;
        }
        var first = $focusables.get(0);
        var last = $focusables.get($focusables.length - 1);
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            $(last).trigger('focus');
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            $(first).trigger('focus');
        }
    }

    function getBoardTabPane($container, tab) {
        return $container.find('.sbir-tab-pane[data-tab="' + tab + '"]').first();
    }

    function getRequestedItemId() {
        var fromContainer = parseInt($('.sbir-board-container').first().data('requested-item-id'), 10);
        if (Number.isFinite(fromContainer) && fromContainer > 0) {
            return fromContainer;
        }
        return 0;
    }

    function setDrawerUrl(url) {
        try {
            var target = url ? new URL(url, window.location.origin) : new URL(window.location.href);
            window.history.replaceState({}, '', target.toString());
        } catch (e) {
            // Ignore URL state errors to avoid breaking drawer UX.
        }
    }

    function clearDrawerUrlParam() {
        try {
            var url = new URL(window.location.href);
            var cleanPath = url.pathname.replace(/\/+$/, '');
            var match = cleanPath.match(/^(.*)\/(roadmap|ideas)\/[^/]+$/);
            if (match) {
                url.pathname = match[1] + '/' + match[2] + '/';
            }
            window.history.replaceState({}, '', url.toString());
        } catch (e) {
            // Ignore URL state errors to avoid blocking close action.
        }
    }

    function setBoardTab($container, tab, updateUrl) {
        var $targetPane = getBoardTabPane($container, tab);
        if (!$targetPane.length) {
            return;
        }

        $container.find('.sbir-tab').removeClass('active').attr('aria-selected', 'false');
        $container.find('.sbir-tab[data-tab="' + tab + '"]').addClass('active').attr('aria-selected', 'true');

        $container.find('.sbir-tab-pane').removeClass('active');
        $targetPane.addClass('active');
        updateBoardSearchPlaceholder($container, tab);

        if ($targetPane.is(':empty') || $.trim($targetPane.text()) === '') {
            var boardId = $container.data('board-id');
            loadViewContent($container, boardId, tab);
        }

        if (updateUrl) {
            var tabHref = $container.find('.sbir-tab[data-tab="' + tab + '"]').attr('href');
            if (tabHref) {
                var url = new URL(tabHref, window.location.origin);
                url.hash = '';
                window.history.replaceState({}, '', url.toString());
            }
        }
    }

    function updateBoardSearchPlaceholder($container, tab) {
        var $input = $container.find('.sbir-board-search-input');
        if (!$input.length) {
            return;
        }
        var placeholder = t('search_roadmap_placeholder', 'Search roadmap items...');
        if (tab === 'ideas') {
            placeholder = t('search_ideas_placeholder', 'Search ideas...');
        } else if (tab === 'announcement') {
            placeholder = t('search_announcement_placeholder', 'Search announcements...');
        }
        $input.attr('placeholder', placeholder);
    }

    function slugifyFilterLabel(label) {
        return $.trim(String(label || ''))
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function updateBoardFilterVisibility($container, tab) {
        var isRoadmap = tab === 'roadmap';
        var isIdeas = tab === 'ideas';
        $container.find('.sbir-board-filter-roadmap').toggle(isRoadmap);
        $container.find('.sbir-board-sort-roadmap').toggle(isRoadmap);
        $container.find('.sbir-board-filter-ideas').toggle(isIdeas);
        $container.find('.sbir-board-sort-ideas').toggle(isIdeas);
    }

    function ensureBoardItemOrderIndexes($container) {
        $container.find('.sbir-kanban-item, .sbir-idea-item').each(function(index) {
            var $item = $(this);
            if (typeof $item.attr('data-order-index') === 'undefined') {
                $item.attr('data-order-index', String(index));
            }
        });
    }

    function parseItemVoteCount($item) {
        var text = $.trim($item.find('.sbir-vote-count-num, .sbir-vote-count').first().text());
        var numeric = parseInt(String(text || '').replace(/[^\d-]/g, ''), 10);
        return isNaN(numeric) ? 0 : numeric;
    }

    function parseItemCreatedTs($item) {
        var numeric = parseInt(String($item.attr('data-created-ts') || '0'), 10);
        return isNaN(numeric) ? 0 : numeric;
    }

    function parseItemOrderIndex($item) {
        var numeric = parseInt(String($item.attr('data-order-index') || '0'), 10);
        return isNaN(numeric) ? 0 : numeric;
    }

    function compareItemsBySortMode(a, b, mode) {
        var $a = $(a);
        var $b = $(b);

        if (mode === 'votes') {
            var aVotes = parseItemVoteCount($a);
            var bVotes = parseItemVoteCount($b);
            if (aVotes !== bVotes) {
                return bVotes - aVotes;
            }
        } else if (mode === 'newest' || mode === 'oldest') {
            var aCreated = parseItemCreatedTs($a);
            var bCreated = parseItemCreatedTs($b);
            if (aCreated !== bCreated) {
                return mode === 'newest' ? (bCreated - aCreated) : (aCreated - bCreated);
            }
        }

        return parseItemOrderIndex($a) - parseItemOrderIndex($b);
    }

    function applyRoadmapSort($container) {
        var mode = String($container.find('.sbir-board-roadmap-sort').val() || 'default');
        var $lists = $container.find('.sbir-tab-pane[data-tab="roadmap"] .sbir-kanban-items');
        $lists.each(function() {
            var $list = $(this);
            var sortedItems = $list.children('.sbir-kanban-item').get().sort(function(a, b) {
                return compareItemsBySortMode(a, b, mode);
            });
            $.each(sortedItems, function(_, itemEl) {
                $list.append(itemEl);
            });
        });
    }

    function applyIdeasSort($container) {
        var mode = String($container.find('.sbir-board-ideas-sort').val() || 'default');
        var $list = $container.find('.sbir-tab-pane[data-tab="ideas"] .sbir-ideas-list').first();
        if (!$list.length) {
            return;
        }
        var sortedItems = $list.children('.sbir-idea-item').get().sort(function(a, b) {
            return compareItemsBySortMode(a, b, mode);
        });
        $.each(sortedItems, function(_, itemEl) {
            $list.append(itemEl);
        });
    }

    function buildRoadmapFilterOptions($container) {
        var $select = $container.find('.sbir-board-card-filter');
        if (!$select.length) {
            return;
        }

        var selected = String($select.val() || 'all');
        var options = [{
            value: 'all',
            label: t('all_cards', 'All cards')
        }];
        var seen = { all: true };

        $container.find('.sbir-tab-pane[data-tab="roadmap"] .sbir-kanban-column[data-status]').each(function() {
            var $column = $(this);
            var status = slugifyFilterLabel($column.data('status'));
            if (!status || seen[status]) {
                return;
            }
            seen[status] = true;
            options.push({
                value: status,
                label: $.trim($column.find('.sbir-head-title').first().text()) || status
            });
        });

        $select.empty();
        $.each(options, function(_, option) {
            $('<option></option>').val(option.value).text(option.label).appendTo($select);
        });
        $select.val(seen[selected] ? selected : 'all');
    }

    function buildIdeasFilterOptions($container) {
        var $select = $container.find('.sbir-board-ideas-filter');
        if (!$select.length) {
            return;
        }

        var selected = String($select.val() || 'all');
        var options = [{
            value: 'all',
            label: t('all_ideas', 'All ideas')
        }];
        var seen = { all: true };
        var hasUncategorized = false;

        $container.find('.sbir-tab-pane[data-tab="ideas"] .sbir-idea-item').each(function() {
            var $item = $(this);
            var itemCategories = [];

            $item.find('.sbir-idea-categories .sbir-category').each(function() {
                var label = $.trim($(this).text());
                var value = slugifyFilterLabel(label);
                if (!value) {
                    return;
                }
                itemCategories.push(value);
                if (!seen[value]) {
                    seen[value] = true;
                    options.push({
                        value: value,
                        label: label
                    });
                }
            });

            if (!itemCategories.length) {
                hasUncategorized = true;
            }

            $item.attr('data-idea-categories', itemCategories.join(','));
        });

        if (hasUncategorized) {
            options.push({
                value: 'uncategorized',
                label: t('uncategorized', 'Uncategorized')
            });
            seen.uncategorized = true;
        }

        $select.empty();
        $.each(options, function(_, option) {
            $('<option></option>').val(option.value).text(option.label).appendTo($select);
        });
        $select.val(seen[selected] ? selected : 'all');
    }

    function buildIdeasPaginationList(current, total) {
        var list = [];
        if (total <= 7) {
            for (var i = 1; i <= total; i++) {
                list.push(i);
            }
            return list;
        }
        var left = Math.max(2, current - 1);
        var right = Math.min(total - 1, current + 1);
        list.push(1);
        if (left > 2) {
            list.push('gap');
        }
        for (var j = left; j <= right; j++) {
            list.push(j);
        }
        if (right < total - 1) {
            list.push('gap');
        }
        list.push(total);
        return list;
    }

    function renderIdeasPaginationNav($container, current, totalPages, total, perPage) {
        var $nav = $container.find('.sbir-ideas-pagination').first();
        if (!$nav.length) {
            return;
        }

        var $pages = $nav.find('.sbir-pagination-pages').empty();
        var $summary = $nav.find('.sbir-pagination-summary');

        if (totalPages <= 1 || total === 0) {
            $nav.attr('hidden', 'hidden');
            return;
        }
        $nav.removeAttr('hidden');

        var pagesToShow = buildIdeasPaginationList(current, totalPages);
        $.each(pagesToShow, function(_, p) {
            var $li = $('<li></li>');
            if (p === 'gap') {
                $li.addClass('sbir-pagination-gap').text('\u2026');
            } else {
                var $btn = $('<button type="button" class="sbir-pagination-page"></button>')
                    .text(String(p))
                    .attr('data-page', String(p));
                if (p === current) {
                    $btn.addClass('is-active').attr('aria-current', 'page');
                }
                $li.append($btn);
            }
            $pages.append($li);
        });

        var startIdx = ((current - 1) * perPage) + 1;
        var endIdx = Math.min(current * perPage, total);
        $summary.text(startIdx + '\u2013' + endIdx + ' ' + t('of', 'of') + ' ' + total);

        $nav.find('.sbir-pagination-prev').prop('disabled', current <= 1);
        $nav.find('.sbir-pagination-next').prop('disabled', current >= totalPages);
    }

    function paginateIdeas($container, targetPage) {
        var $list = $container.find('.sbir-tab-pane[data-tab="ideas"] .sbir-ideas-list').first();
        if (!$list.length) {
            return;
        }

        var perPage = parseInt(String($list.attr('data-ideas-per-page') || '15'), 10);
        if (!perPage || perPage < 1) {
            perPage = 15;
        }

        $list.children('.sbir-idea-item').removeClass('sbir-page-hidden');

        var $visibleItems = $list.children('.sbir-idea-item').filter(function() {
            return $(this).css('display') !== 'none';
        });
        var total = $visibleItems.length;
        var totalPages = Math.max(1, Math.ceil(total / perPage));
        var current = parseInt(targetPage, 10) || 1;
        if (current < 1) {
            current = 1;
        }
        if (current > totalPages) {
            current = totalPages;
        }
        $list.attr('data-current-page', String(current));

        $visibleItems.each(function(index) {
            var pageOfItem = Math.floor(index / perPage) + 1;
            if (pageOfItem !== current) {
                $(this).addClass('sbir-page-hidden');
            }
        });

        renderIdeasPaginationNav($container, current, totalPages, total, perPage);
    }

    function applyBoardSearch($container, query) {
        var needle = $.trim(String(query || '').toLowerCase());
        var $activePane = $container.find('.sbir-tab-pane.active').first();
        if (!$activePane.length) {
            return;
        }

        var activeTab = String($activePane.data('tab') || '');
        var cardFilter = String($container.find('.sbir-board-card-filter').val() || 'all');
        var ideasFilter = String($container.find('.sbir-board-ideas-filter').val() || 'all');

        ensureBoardItemOrderIndexes($container);
        if (activeTab === 'roadmap') {
            applyRoadmapSort($container);
        } else if (activeTab === 'ideas') {
            applyIdeasSort($container);
        }

        if (activeTab === 'roadmap') {
            $activePane.find('.sbir-kanban-column[data-status]').each(function() {
                var $column = $(this);
                var status = slugifyFilterLabel($column.data('status'));
                var showColumn = cardFilter === 'all' || status === cardFilter;
                $column.toggle(showColumn);
            });
        } else {
            $container.find('.sbir-tab-pane[data-tab="roadmap"] .sbir-kanban-column').show();
        }

        var $items = $activePane.find('.sbir-kanban-item, .sbir-idea-item');
        if (!$items.length) {
            return;
        }

        var visibleCount = 0;
        $items.each(function() {
            var $item = $(this);
            var haystack = $.trim($item.text()).toLowerCase();
            var matched = needle === '' || haystack.indexOf(needle) !== -1;

            if (activeTab === 'ideas' && ideasFilter !== 'all') {
                var categories = String($item.attr('data-idea-categories') || '');
                if (ideasFilter === 'uncategorized') {
                    matched = matched && categories === '';
                } else {
                    var categoryList = categories === '' ? [] : categories.split(',');
                    matched = matched && categoryList.indexOf(ideasFilter) !== -1;
                }
            }
            $item.toggle(matched);
            if (matched) {
                visibleCount++;
            }
        });

        // Show a simple empty state when search has no matches in current tab.
        var $empty = $activePane.find('.sbir-search-empty');
        if (!$empty.length) {
            $empty = $('<p class="sbir-notice sbir-search-empty" style="display:none;"></p>');
            $activePane.append($empty);
        }

        var hasActiveFilter = (activeTab === 'roadmap' && cardFilter !== 'all') || (activeTab === 'ideas' && ideasFilter !== 'all');
        if ((needle !== '' || hasActiveFilter) && visibleCount === 0) {
            $empty.text(t('no_search_matches', 'No matching items found.')).show();
        } else {
            $empty.hide();
        }

        if (activeTab === 'ideas') {
            paginateIdeas($container, 1);
        }
    }

    // Debounce utility function
    function debounce(func, wait) {
        var timeout;
        return function() {
            var context = this, args = arguments;
            var later = function() {
                timeout = null;
                func.apply(context, args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // Throttle utility function
    function throttle(func, limit) {
        var inThrottle;
        return function() {
            var args = arguments;
            var context = this;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(function() {
                    inThrottle = false;
                }, limit);
            }
        };
    }

    // Load specific view content
    function loadViewContent($container, boardId, viewType) {
        if (viewType !== 'roadmap' && viewType !== 'ideas') {
            return;
        }
        $.ajax({
            url: sbir_public.ajax_url,
            type: 'POST',
            data: {
                action: 'sbir_load_board_content',
                nonce: sbir_public.nonce,
                board_id: boardId,
                view_type: viewType
            },
            success: function(response) {
                if (response.success) {
                    var $targetPane = getBoardTabPane($container, viewType);
                    $targetPane.html(response.data.content).show();
                    ensureBoardItemOrderIndexes($container);
                    buildRoadmapFilterOptions($container);
                    buildIdeasFilterOptions($container);
                    initializeOptimizedVoting($container);
                    initializeKanbanSorting($targetPane);
                    if (viewType === 'ideas') {
                        paginateIdeas($container, 1);
                    }
                }
            }
        });
    }

    function initializeOptimizedVoting($scope) {
        $scope = $scope || $(document);
        
        // Remove existing handlers to prevent duplicates
        $scope.off('click', '.sbir-vote-btn');
        
        $scope.on('click', '.sbir-vote-btn', function() {
            var $btn = $(this);
            var itemId = $btn.data('item-id');
            if (!itemId) return;

            // Guard per-item to prevent double submissions
            var $all = $('.sbir-vote-btn[data-item-id="' + itemId + '"]');
            if ($all.data('busy')) return;
            $all.data('busy', true);

            var originalNum = parseInt($btn.find('.sbir-vote-count-num').text(), 10) || 0;
            var wasVoted = $btn.hasClass('voted');
            var optimisticNum = wasVoted ? Math.max(0, originalNum - 1) : originalNum + 1;

            // Optimistic: update ALL instances instantly
            $all.each(function(){
                var $b = $(this);
                $b.find('.sbir-vote-count-num').text(optimisticNum);
                $b.toggleClass('voted', !wasVoted);
            });

            // Fire request immediately (no queue)
            $.ajax({
                url: sbir_public.ajax_url,
                type: 'POST',
                data: { action: 'sbir_vote', nonce: sbir_public.nonce, item_id: itemId },
                success: function(response){
                    if (response && response.success) {
                        $all.each(function(){
                            var $b = $(this);
                            $b.find('.sbir-vote-count-num').text(response.data.votes);
                            $b.toggleClass('voted', !!response.data.voted);
                        });
                    } else {
                        // Revert on server-reported error
                        $all.each(function(){
                            var $b = $(this);
                            $b.find('.sbir-vote-count-num').text(originalNum);
                            $b.toggleClass('voted', wasVoted);
                        });
                    }
                },
                error: function(){
                    // Revert on transport error
                    $all.each(function(){
                        var $b = $(this);
                        $b.find('.sbir-vote-count-num').text(originalNum);
                        $b.toggleClass('voted', wasVoted);
                    });
                },
                complete: function(){
                    // Release guard quickly
                    setTimeout(function(){ $all.removeData('busy'); }, 50);
                }
            });
        });

    }

    // Form handling with optimization
    function initializeFormHandling() {
        // Idea modal open/close
        function sbirOpenIdeaModal($container) {
            var $modal = $container.find('.sbir-idea-modal');
            if (!$modal.length) return;
            $modal.addClass('open').attr('aria-hidden', 'false');
            $('body').addClass('sbir-modal-open');
            $modal.data('sbir-prev-focus', document.activeElement);
            $modal.find('#sbir-title').trigger('focus');
        }

        function sbirCloseIdeaModal($modal) {
            $modal.removeClass('open').attr('aria-hidden', 'true');
            $('body').removeClass('sbir-modal-open');
            $modal.find('#sbir-idea-form')[0].reset();
            $modal.find('.sbir-form-message').empty().removeClass('success error');
            var previousFocus = $modal.data('sbir-prev-focus');
            if (previousFocus && typeof previousFocus.focus === 'function') {
                previousFocus.focus();
            }
        }

        $(document).on('click', '.sbir-submit-idea-btn', function() {
            var $container = $(this).closest('.sbir-board-container');
            sbirOpenIdeaModal($container);
        });

        $(document).on('click', '.sbir-idea-modal .sbir-modal-close, .sbir-idea-modal .sbir-modal-cancel, .sbir-idea-modal .sbir-modal-overlay', function() {
            var $modal = $(this).closest('.sbir-idea-modal');
            sbirCloseIdeaModal($modal);
        });
        
        // Handle idea submission with debouncing
        var debouncedSubmit = debounce(function($form) {
            var $submitBtn = $form.find('[type="submit"]');
            var $message = $form.closest('.sbir-idea-modal').find('.sbir-form-message');
            
            $submitBtn.prop('disabled', true).text(sbir_public.loading_text || t('submitting', 'Submitting...'));
            $message.empty().removeClass('success error');
            
            var formData = $form.serialize() + '&action=sbir_submit_idea&nonce=' + sbir_public.nonce;
            
            $.ajax({
                url: sbir_public.ajax_url,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        $message.addClass('success').text(response.data.message);
                        $form[0].reset();
                        sbirCloseIdeaModal($form.closest('.sbir-idea-modal'));
                        location.reload(); // Refresh to show new idea
                    } else {
                        $message.addClass('error').text(response.data.message || t('error_submitting_idea', 'Error submitting idea'));
                    }
                },
                error: function() {
                    $message.addClass('error').text(t('connection_error', 'Connection error. Please try again.'));
                },
                complete: function() {
                    $submitBtn.prop('disabled', false).text(t('submit_idea', 'Submit Idea'));
                }
            });
        }, 1000);
        
        $(document).on('submit', '#sbir-idea-form', function(e) {
            e.preventDefault();
            debouncedSubmit($(this));
        });

        // Move to roadmap is handled inside the drawer modal
    }

    // Kanban sorting with throttling. Accepts optional scope so we can re-init
    // after dynamic pane reloads (autosave, tab switching).
    function initializeKanbanSorting($scope) {
        if (typeof sbir_public === 'undefined' || !sbir_public.current_user_can_manage || !$.fn.sortable) {
            return;
        }
        var $root = ($scope && $scope.length) ? $scope : $(document);
        var $lists = $root.find('.sbir-kanban-items');
        if (!$lists.length) { return; }
        $lists.each(function(){
            var $list = $(this);
            if ($list.hasClass('ui-sortable')) {
                $list.sortable('destroy');
            }
            $list.sortable({
                connectWith: '.sbir-kanban-items',
                placeholder: 'sbir-sortable-placeholder',
                forcePlaceholderSize: true,
                tolerance: 'pointer',
                items: '> .sbir-kanban-item',
                dropOnEmpty: true,
                start: function(event, ui) {
                    ui.placeholder.height(ui.item.outerHeight());
                },
                receive: throttle(function(event, ui) {
                    var $item = ui.item;
                    var itemId = $item.data('item-id');
                    var $col = $item.closest('.sbir-kanban-column');
                    var newStatus = $col.data('status');

                    $.ajax({
                        url: sbir_public.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'sbir_update_status_front',
                            nonce: sbir_public.nonce,
                            item_id: itemId,
                            status: newStatus
                        },
                        success: function(response) {
                            if (!response.success) {
                                location.reload();
                            }
                        },
                        error: function() {
                            location.reload();
                        }
                    });
                }, 500)
            });
        });
    }

    $(document).ready(function() {
        
        // Initialize all components
        initializeOptimizedVoting();
        initializeFormHandling();
        initializeKanbanSorting();
        // Drawer open handled below via sbirOpenDrawer helper
        
        // Tab switching with throttling
        $('.sbir-tab').on('click', throttle(function(e) {
            e.preventDefault();
            var tab = $(this).data('tab');
            var $container = $(this).closest('.sbir-board-container');
            setBoardTab($container, tab, true);
            updateBoardFilterVisibility($container, tab);
            var $search = $container.find('.sbir-board-search-input');
            if ($search.length) {
                applyBoardSearch($container, $search.val());
            }
        }, 100));

        // Keep search placeholder aligned with server-selected active tab.
        $('.sbir-board-container').each(function() {
            var $container = $(this);
            var activeTab = $container.find('.sbir-tab.active').first().data('tab')
                || $container.data('default-tab')
                || 'roadmap';
            ensureBoardItemOrderIndexes($container);
            buildRoadmapFilterOptions($container);
            buildIdeasFilterOptions($container);
            updateBoardFilterVisibility($container, activeTab);
            updateBoardSearchPlaceholder($container, activeTab);
            var $search = $container.find('.sbir-board-search-input');
            if ($search.length) {
                applyBoardSearch($container, $search.val());
            }
        });

        // Board search (applies to current active tab).
        $(document).on('input', '.sbir-board-search-input', function() {
            var $input = $(this);
            var $container = $input.closest('.sbir-board-container');
            applyBoardSearch($container, $input.val());
        });

        // Board filters (applies to current active tab).
        $(document).on('change', '.sbir-board-filter-select', function() {
            var $filter = $(this);
            var $container = $filter.closest('.sbir-board-container');
            var $search = $container.find('.sbir-board-search-input');
            applyBoardSearch($container, $search.val());
        });

        // Ideas pagination: numbered page buttons.
        $(document).on('click', '.sbir-pagination-page', function() {
            var $btn = $(this);
            var $container = $btn.closest('.sbir-board-container');
            var page = parseInt(String($btn.attr('data-page') || '1'), 10) || 1;
            paginateIdeas($container, page);

            var $list = $container.find('.sbir-tab-pane[data-tab="ideas"] .sbir-ideas-list').first();
            if ($list.length && $list.offset()) {
                var top = $list.offset().top - 20;
                $('html, body').animate({ scrollTop: top < 0 ? 0 : top }, 160);
            }
        });

        // Ideas pagination: prev/next buttons.
        $(document).on('click', '.sbir-pagination-prev, .sbir-pagination-next', function() {
            var $btn = $(this);
            if ($btn.prop('disabled')) {
                return;
            }
            var $container = $btn.closest('.sbir-board-container');
            var $list = $container.find('.sbir-tab-pane[data-tab="ideas"] .sbir-ideas-list').first();
            if (!$list.length) {
                return;
            }
            var current = parseInt(String($list.attr('data-current-page') || '1'), 10) || 1;
            var direction = String($btn.attr('data-direction') || 'next');
            var newPage = direction === 'prev' ? current - 1 : current + 1;
            paginateIdeas($container, newPage);

            if ($list.offset()) {
                var top = $list.offset().top - 20;
                $('html, body').animate({ scrollTop: top < 0 ? 0 : top }, 160);
            }
        });

        // Deep-link: open item drawer from board URL path item slug.
        var requestedItemId = getRequestedItemId();
        if (requestedItemId > 0) {
            var selector = '.sbir-open-drawer[data-item-id="' + requestedItemId + '"], .sbir-kanban-item[data-item-id="' + requestedItemId + '"]';
            var $targetLink = $(selector).first();
            var $targetContainer = $targetLink.closest('.sbir-board-container');

            if ($targetContainer.length) {
                if ($targetLink.hasClass('sbir-open-drawer')) {
                    setBoardTab($targetContainer, 'ideas', false);
                } else if ($targetLink.hasClass('sbir-kanban-item')) {
                    setBoardTab($targetContainer, 'roadmap', false);
                }
            }

            setTimeout(function() {
                var $resolved = $(selector).first();
                if ($resolved.length) {
                    sbirOpenDrawer($resolved.attr('href') || '', requestedItemId);
                } else {
                    sbirOpenDrawer('', requestedItemId);
                }
            }, 120);
        }

        // Roadmap modal open/close
        function sbirOpenRoadmapModal(statusId, $container) {
            var $modal = $container.find('.sbir-roadmap-modal');
            if (!$modal.length) return;
            $modal.find('select[name="status_id"]').val(statusId || '');
            $modal.find('input[name="item_id"]').val('');
            $modal.find('.sbir-modal-title').text(t('add_roadmap_item', 'Add Roadmap Item'));
            $modal.addClass('open').attr('aria-hidden', 'false');
            $('body').addClass('sbir-modal-open');
            $modal.data('sbir-prev-focus', document.activeElement);
            $modal.find('#sbir-roadmap-title').trigger('focus');
        }

        function sbirCloseRoadmapModal($modal) {
            $modal.removeClass('open').attr('aria-hidden', 'true');
            $('body').removeClass('sbir-modal-open');
            var $roadmapForm = $modal.find('.sbir-roadmap-form');
            var $moveForm = $modal.find('.sbir-move-roadmap-form');
            if ($roadmapForm.length) {
                $roadmapForm[0].reset();
            }
            if ($moveForm.length) {
                $moveForm[0].reset();
            }
            $modal.find('.sbir-form-message').empty().removeClass('success error');
            var previousFocus = $modal.data('sbir-prev-focus');
            if (previousFocus && typeof previousFocus.focus === 'function') {
                previousFocus.focus();
            }
        }

        $(document).on('click', '.sbir-add-btn', function() {
            var $btn = $(this);
            var statusId = $btn.data('status-id') || '';
            var $container = $btn.closest('.sbir-board-container');
            sbirOpenRoadmapModal(statusId, $container);
        });

        $(document).on('click', '.sbir-modal-close, .sbir-modal-cancel, .sbir-modal-overlay', function() {
            var $modal = $(this).closest('.sbir-roadmap-modal');
            sbirCloseRoadmapModal($modal);
        });

        // Move to roadmap modal (drawer)
        $(document).on('click', '.sbir-move-to-roadmap-btn', function(e) {
            e.preventDefault();
            var $drawer = $('#sbir-drawer');
            var $modal = $drawer.find('.sbir-move-modal');
            if (!$modal.length) { return; }
            $modal.data('sbir-prev-focus', document.activeElement);
            $modal.addClass('open').attr('aria-hidden', 'false');
            $('body').addClass('sbir-modal-open');
            $modal.find('#sbir-move-status').trigger('focus');
        });

        $(document).on('submit', '.sbir-move-roadmap-form', function(e) {
            e.preventDefault();
            var $form = $(this);
            var $message = $form.find('.sbir-form-message');
            var $submit = $form.find('[type="submit"]');

            $submit.prop('disabled', true).text(sbir_public.loading_text || t('moving', 'Moving...'));
            $message.empty().removeClass('success error');

            $.post(sbir_public.ajax_url, $form.serialize() + '&action=sbir_move_to_roadmap_front&nonce=' + sbir_public.nonce)
                .done(function(resp){
                    if (resp && resp.success) {
                        $message.addClass('success').text(resp.data.message || t('moved', 'Moved'));
                        location.reload();
                    } else {
                        $message.addClass('error').text((resp && resp.data && resp.data.message) || t('error_moving', 'Error moving'));
                    }
                })
                .fail(function(){
                    $message.addClass('error').text(t('connection_error', 'Connection error. Please try again.'));
                })
                .always(function(){
                    $submit.prop('disabled', false).text(t('move', 'Move'));
                });
        });

        // Edit roadmap item inline in drawer
        $(document).on('click', '.sbir-edit-btn-drawer', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var $drawer = $('#sbir-drawer');
            if (!$drawer.length) return;
            $drawer.addClass('sbir-editing');
            $drawer.find('.sbir-edit-field').first().focus();
        });

        $(document).on('input', '#sbir-drawer [data-title-source]', function() {
            $('#sbir-title-hidden').val($(this).val());
        });

        // Autosave for drawer edit form (replaces explicit Save Changes).
        var sbirAutosaveTimer = null;
        var sbirAutosaveInFlight = false;
        var sbirAutosavePending = false;
        var sbirAutosaveDelay = 700;

        function sbirSetAutosaveStatus($drawer, state, text) {
            var $status = $drawer.find('.sbir-autosave-status');
            if (!$status.length) { return; }
            $status.attr('data-state', state).text(text || '');
        }

        function sbirRefreshBoardPaneForDrawer($drawer) {
            var $container = $drawer.closest('.sbir-board-container');
            if (!$container.length) {
                $container = $('.sbir-board-container').first();
            }
            if (!$container.length) { return; }
            var boardId = $container.data('board-id');
            if (!boardId) { return; }
            var view = $container.find('.sbir-tab.active').first().data('tab')
                || $container.data('default-tab')
                || 'roadmap';
            if (view !== 'roadmap' && view !== 'ideas') { return; }
            loadViewContent($container, boardId, view);
        }

        function sbirRunDrawerAutosave($drawer) {
            var $form = $drawer.find('.sbir-drawer-edit-form');
            if (!$form.length) { return; }
            var itemId = $form.find('input[name="item_id"]').val();
            if (!itemId) { return; }

            if (sbirAutosaveInFlight) {
                sbirAutosavePending = true;
                return;
            }

            var $titleInput = $drawer.find('[data-title-source]');
            if ($titleInput.length) {
                $form.find('#sbir-title-hidden').val($titleInput.val());
            }

            sbirAutosaveInFlight = true;
            sbirSetAutosaveStatus($drawer, 'saving', t('saving', 'Saving...'));

            var data = $form.serialize() + '&action=sbir_update_roadmap_item&nonce=' + sbir_public.nonce;
            $.post(sbir_public.ajax_url, data)
                .done(function(resp){
                    if (resp && resp.success) {
                        sbirSetAutosaveStatus($drawer, 'saved', t('all_changes_saved', 'All changes saved'));
                        sbirRefreshBoardPaneForDrawer($drawer);
                    } else {
                        sbirSetAutosaveStatus($drawer, 'error', (resp && resp.data && resp.data.message) || t('error_saving', 'Error saving'));
                    }
                })
                .fail(function(){
                    sbirSetAutosaveStatus($drawer, 'error', t('connection_error', 'Connection error. Please try again.'));
                })
                .always(function(){
                    sbirAutosaveInFlight = false;
                    if (sbirAutosavePending) {
                        sbirAutosavePending = false;
                        clearTimeout(sbirAutosaveTimer);
                        sbirAutosaveTimer = setTimeout(function(){ sbirRunDrawerAutosave($drawer); }, 100);
                    }
                });
        }

        function sbirScheduleDrawerAutosave($drawer) {
            clearTimeout(sbirAutosaveTimer);
            sbirAutosaveTimer = setTimeout(function(){ sbirRunDrawerAutosave($drawer); }, sbirAutosaveDelay);
        }

        $(document).on('input.sbirAutosave change.sbirAutosave', '#sbir-drawer .sbir-drawer-edit-form input, #sbir-drawer .sbir-drawer-edit-form select, #sbir-drawer .sbir-drawer-edit-form textarea', function() {
            sbirScheduleDrawerAutosave($('#sbir-drawer'));
        });

        $(document).on('input.sbirAutosave', '#sbir-drawer [data-title-source]', function() {
            sbirScheduleDrawerAutosave($('#sbir-drawer'));
        });

        // Prevent accidental form submit (Enter in inputs) – autosave handles it.
        $(document).on('submit', '.sbir-drawer-edit-form', function(e) {
            e.preventDefault();
            sbirRunDrawerAutosave($('#sbir-drawer'));
        });

        // Close any open guest-subscribe panel when clicking outside it.
        $(document).on('mousedown.sbirSubscribeOutside', function(e) {
            var $target = $(e.target);
            // Click inside any subscribe widget? Leave the panel as-is.
            if ($target.closest('.sbir-subscribe').length) {
                return;
            }
            $('.sbir-subscribe-guest').not('[hidden]').prop('hidden', true);
        });
        // Escape closes an open guest-subscribe panel too.
        $(document).on('keydown.sbirSubscribeEsc', function(e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                $('.sbir-subscribe-guest').not('[hidden]').prop('hidden', true);
            }
        });

        // Subscribe button (logged-in toggle / guest reveals email field)
        $(document).on('click', '.sbir-subscribe-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $wrap = $btn.closest('.sbir-subscribe');
            var itemId = parseInt($btn.data('item-id'), 10) || 0;
            if (!itemId) { return; }

            var $guest = $wrap.find('.sbir-subscribe-guest');
            var $msg = $wrap.find('.sbir-subscribe-message');
            $msg.empty().removeClass('error');

            // Guest flow: reveal email field on first click.
            if ($guest.length && $guest.prop('hidden')) {
                $guest.prop('hidden', false);
                $guest.find('.sbir-subscribe-email').trigger('focus');
                return;
            }

            // Logged-in toggle subscribe/unsubscribe.
            var isSubscribed = $btn.hasClass('is-subscribed');
            $btn.prop('disabled', true);
            $.post(sbir_public.ajax_url, {
                action: isSubscribed ? 'sbir_unsubscribe_item' : 'sbir_subscribe_item',
                nonce: sbir_public.nonce,
                item_id: itemId
            }).done(function(resp) {
                if (resp && resp.success) {
                    var nowSubscribed = !!resp.data.subscribed;
                    $btn.toggleClass('is-subscribed', nowSubscribed)
                        .attr('aria-pressed', nowSubscribed ? 'true' : 'false');
                    $btn.find('.sbir-subscribe-label').text(nowSubscribed
                        ? t('subscribed', 'Subscribed')
                        : t('subscribe', 'Subscribe'));
                    $msg.text(resp.data.message || '');
                } else {
                    $msg.addClass('error').text((resp && resp.data && resp.data.message) || t('connection_error', 'Connection error. Please try again.'));
                }
            }).fail(function() {
                $msg.addClass('error').text(t('connection_error', 'Connection error. Please try again.'));
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });

        // Subscribe button (guest email confirm)
        $(document).on('click', '.sbir-subscribe-confirm', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $wrap = $btn.closest('.sbir-subscribe');
            var itemId = parseInt($wrap.data('item-id'), 10) || 0;
            var email = ($wrap.find('.sbir-subscribe-email').val() || '').trim();
            var $msg = $wrap.find('.sbir-subscribe-message');
            $msg.empty().removeClass('error');

            if (!itemId || !email) {
                $msg.addClass('error').text(t('subscribe_email_required', 'Please enter a valid email address.'));
                return;
            }

            $btn.prop('disabled', true);
            $.post(sbir_public.ajax_url, {
                action: 'sbir_subscribe_item',
                nonce: sbir_public.nonce,
                item_id: itemId,
                email: email
            }).done(function(resp) {
                if (resp && resp.success) {
                    $wrap.find('.sbir-subscribe-guest').prop('hidden', true);
                    $wrap.find('.sbir-subscribe-btn')
                        .addClass('is-subscribed')
                        .attr('aria-pressed', 'true')
                        .find('.sbir-subscribe-label').text(t('subscribed', 'Subscribed'));
                    $msg.text(resp.data.message || t('subscribe_success', 'Subscribed for updates.'));
                } else {
                    $msg.addClass('error').text((resp && resp.data && resp.data.message) || t('subscribe_email_required', 'Please enter a valid email address.'));
                }
            }).fail(function() {
                $msg.addClass('error').text(t('connection_error', 'Connection error. Please try again.'));
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });

        $(document).on('submit', '.sbir-roadmap-form', function(e) {
            e.preventDefault();
            var $form = $(this);
            var $modal = $form.closest('.sbir-roadmap-modal');
            var $message = $form.find('.sbir-form-message');
            var $submit = $form.find('[type="submit"]');

            $submit.prop('disabled', true).text(sbir_public.loading_text || t('saving', 'Saving...'));
            $message.empty().removeClass('success error');

            var formData = new FormData($form[0]);
            var itemId = $form.find('input[name="item_id"]').val();
            var actionName = itemId ? 'sbir_update_roadmap_item' : 'sbir_add_roadmap_item';
            formData.append('action', actionName);
            formData.append('nonce', sbir_public.nonce);
            $.ajax({
                url: sbir_public.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response && response.success) {
                        $message.addClass('success').text(response.data.message || t('created', 'Created'));
                        setTimeout(function() {
                            sbirCloseRoadmapModal($modal);
                            location.reload();
                        }, 300);
                    } else {
                        $message.addClass('error').text((response && response.data && response.data.message) || t('error_creating_item', 'Error creating item'));
                    }
                },
                error: function() {
                    $message.addClass('error').text(t('connection_error', 'Connection error. Please try again.'));
                },
                complete: function() {
                    $submit.prop('disabled', false).text(t('create_item', 'Create Item'));
                }
            });
        });

        // Drawer helpers
        function sbirLoadDrawerContent($drawer, itemId, url) {
            var skeleton = '<div class="sbir-skeleton" style="height:22px; width:42%; margin:10px 0 6px;"></div>' +
                           '<div class="sbir-skeleton" style="height:12px; width:56%; margin:6px 0 12px;"></div>' +
                           '<div class="sbir-skeleton" style="height:180px; width:100%; margin:12px 0 0;"></div>';
            $drawer.find('.sbir-drawer-content').html(skeleton);

            function loadFromUrl() {
                if (!url) {
                    return;
                }
                $drawer.find('.sbir-drawer-content').load(url + ' .sbir-item-single', function() {
                    if ($drawer.find('.sbir-drawer-edit-form').length) {
                        $drawer.addClass('sbir-editing');
                    }
                    initializeOptimizedVoting($drawer);
                });
            }

            if (itemId) {
                $.ajax({
                    url: sbir_public.ajax_url,
                    type: 'POST',
                    data: { action: 'sbir_get_item_drawer', nonce: sbir_public.nonce, item_id: itemId },
                    success: function(resp){
                        if (resp && resp.success && resp.data && resp.data.content) {
                            $drawer.find('.sbir-drawer-content').html(resp.data.content);
                            if ($drawer.find('.sbir-drawer-edit-form').length) {
                                $drawer.addClass('sbir-editing');
                            }
                            initializeOptimizedVoting($drawer);
                        } else {
                            loadFromUrl();
                        }
                    },
                    error: function(){
                        loadFromUrl();
                    }
                });
                return;
            }

            loadFromUrl();
        }

        function getDrawerState($drawer) {
            return {
                url: $drawer.data('sbir-url') || window.location.href,
                itemId: $drawer.data('sbir-item-id') || null
            };
        }

        function refreshDrawerContent($drawer) {
            var state = getDrawerState($drawer);
            sbirLoadDrawerContent($drawer, state.itemId, state.url);
        }

        window.SBIRPublicCore = window.SBIRPublicCore || {};
        window.SBIRPublicCore.t = t;
        window.SBIRPublicCore.refreshDrawerContent = refreshDrawerContent;

        function sbirGetDrawerHost($source) {
            var $host = $();
            if ($source && $source.length) {
                $host = $source.closest('.sbir-board-container');
            }
            if (!$host.length) {
                $host = $('.sbir-board-container').first();
            }
            return $host;
        }

        function sbirSyncDrawerTheme($drawer, $host) {
            if (!$drawer.length) {
                return;
            }

            // Clear previous sync state.
            $drawer.removeAttr('style');
            $drawer.removeAttr('data-sbirp-theme');

            if (!$host || !$host.length) {
                return;
            }

            // Copy only CSS custom properties (design tokens) from the host's
            // inline style. We must NOT copy layout properties (width, padding,
            // max-width) because they would constrain the fixed-positioned
            // drawer and break its internal flex layout (e.g. character-wrapped
            // comment editor). Custom properties propagate naturally through
            // inheritance to child components.
            var hostStyleObj = $host[0] && $host[0].style ? $host[0].style : null;
            if (hostStyleObj) {
                var tokenParts = [];
                for (var i = 0; i < hostStyleObj.length; i++) {
                    var propName = hostStyleObj[i];
                    if (propName && propName.indexOf('--') === 0) {
                        var propValue = hostStyleObj.getPropertyValue(propName);
                        if (propValue) {
                            tokenParts.push(propName + ':' + propValue);
                        }
                    }
                }
                if (tokenParts.length) {
                    $drawer.attr('style', tokenParts.join(';'));
                }
            }

            var hostTheme = $host.attr('data-sbirp-theme') || '';
            if (hostTheme) {
                $drawer.attr('data-sbirp-theme', hostTheme);
            }
        }

        function sbirOpenDrawer(url, itemId, $source){
            var $host = sbirGetDrawerHost($source);
            var $drawer = $('#sbir-drawer');
            if(!$drawer.length){
                $drawer = $('<div id="sbir-drawer" class="sbir-drawer" aria-modal="true" role="dialog"><div class="sbir-drawer-overlay"></div><div class="sbir-drawer-panel"><button class="sbir-drawer-close" aria-label="' + t('close', 'Close') + '"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg></button><div class="sbir-drawer-content"></div></div></div>');
                // Always append drawer to <body> so it escapes any theme-created
                // stacking context (transform/filter/position on wrappers like
                // Colibri's fixed header). Theme styling is still synced via
                // sbirSyncDrawerTheme() using design tokens from $host.
                $('body').append($drawer);
            } else if (!$drawer.parent().is('body')) {
                $('body').append($drawer);
            }

            sbirSyncDrawerTheme($drawer, $host);
            $drawer.data('sbir-prev-focus', document.activeElement);
            $('body').addClass('sbir-drawer-open');
            $drawer.addClass('open');
            $drawer.data('sbir-url', url);
            $drawer.data('sbir-item-id', itemId || '');
            setDrawerUrl(url);
            sbirLoadDrawerContent($drawer, itemId, url);
            // Sync vote state for any buttons inside the drawer
            setTimeout(function(){
                $drawer.find('.sbir-vote-btn').each(function(){
                    var $btn = $(this);
                    var voteItemId = $btn.data('item-id');
                    if(!voteItemId) { return; }
                    $.ajax({
                        url: sbir_public.ajax_url,
                        type: 'POST',
                        data: { action: 'sbir_vote_status', nonce: sbir_public.nonce, item_id: voteItemId },
                        success: function(resp){
                            if(resp && resp.success && resp.data){
                                var voteCount = parseInt(resp.data.votes, 10) || 0;
                                var hasVoted = resp.data.voted === true || resp.data.voted === 'true' || resp.data.voted === 1;
                                $btn.find('.sbir-vote-count-num').text(voteCount);
                                $btn.toggleClass('voted', hasVoted);
                            }
                        }
                    });
                });
            }, 150);
        }

        $(document).on('click', '.sbir-drawer-close, .sbir-drawer-overlay', function(){
            var $drawer = $('#sbir-drawer');
            $drawer.removeClass('open sbir-editing');
            $('body').removeClass('sbir-drawer-open');
            clearDrawerUrlParam();
            var previousFocus = $drawer.data('sbir-prev-focus');
            if (previousFocus && typeof previousFocus.focus === 'function') {
                previousFocus.focus();
            }
        });

        // Open drawer for ideas titles
        $(document).on('click', '.sbir-open-drawer', function(e){
            e.preventDefault();
            var $link = $(this);
            var itemId = $link.data('item-id') || null;
            sbirOpenDrawer($link.attr('href'), itemId, $link);
        });

        // Open drawer for roadmap cards (avoid vote button clicks)
        $(document).on('click', '.sbir-kanban-item', function(e){
            if ($(e.target).closest('.sbir-vote-btn, .sbir-kanban-vote').length) return; // allow voting
            e.preventDefault();
            var $card = $(this);
            var itemId = $card.data('item-id') || null;
            sbirOpenDrawer($card.attr('href'), itemId, $card);
        });

        // Discussion/comment handlers are loaded from sbir-public-comments.js.

        // Keyboard accessibility: Esc closes modal/drawer, Tab traps focus.
        $(document).on('keydown', function(e) {
            if (e.key !== 'Escape' && e.key !== 'Tab') {
                return;
            }

            var $openModal = $('.sbir-roadmap-modal.open, .sbir-idea-modal.open').last();
            if ($openModal.length) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    if ($openModal.hasClass('sbir-idea-modal')) {
                        $openModal.find('.sbir-modal-close').trigger('click');
                    } else {
                        sbirCloseRoadmapModal($openModal);
                    }
                    return;
                }
                trapFocusIn($openModal.find('.sbir-modal-panel'), e);
                return;
            }

            var $drawerOpen = $('#sbir-drawer.open');
            if ($drawerOpen.length) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    $drawerOpen.find('.sbir-drawer-close').trigger('click');
                    return;
                }
                trapFocusIn($drawerOpen.find('.sbir-drawer-panel'), e);
            }
        });
    });

})(jQuery);