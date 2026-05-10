/**
 * SimpleBoards Public Comments JavaScript
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        var core = window.SBIRPublicCore || {};

        function t(key, fallback) {
            if (typeof core.t === 'function') {
                return core.t(key, fallback);
            }
            if (window.sbir_public && window.sbir_public.i18n && window.sbir_public.i18n[key]) {
                return window.sbir_public.i18n[key];
            }
            return fallback;
        }

        function refreshDrawerContent($drawer) {
            if (typeof core.refreshDrawerContent === 'function') {
                core.refreshDrawerContent($drawer);
            }
        }

        function syncEditor($editor) {
            var targetId = $editor.data('target');
            if (!targetId) {
                return;
            }
            var html = $editor.html();
            if (html === '<br>') {
                html = '';
            }
            var $textarea = $('#' + targetId);
            if ($textarea.length) {
                $textarea.val(html);
            }
        }

        function normalizeLinks($editor) {
            $editor.find('a').attr({
                target: '_blank',
                rel: 'noopener noreferrer'
            });
        }

        function applyTextFormat($editor, action) {
            if (!$editor || !$editor.length) {
                return;
            }
            $editor.focus();
            if (action === 'link') {
                var url = window.prompt(t('enter_url', 'Enter URL'), 'https://');
                if (!url) {
                    return;
                }
                document.execCommand('createLink', false, url);
            } else if (action === 'bold') {
                document.execCommand('bold', false, null);
            } else if (action === 'italic') {
                document.execCommand('italic', false, null);
            } else if (action === 'underline') {
                document.execCommand('underline', false, null);
            }
            normalizeLinks($editor);
            syncEditor($editor);
        }

        function toggleCommentButton($scope) {
            var $ta = $scope.find('textarea#comment');
            var $btn = $scope.find('input[type="submit"], .sbir-btn.sbir-btn-primary');
            var has = $.trim($ta.val()).length > 0;
            $btn.prop('disabled', !has).toggleClass('is-disabled', !has).toggleClass('is-active', has);
        }

        // Keep comment submission inside drawer: AJAX post then reload drawer content.
        $(document).on('submit', '#sbir-drawer form.sbir-comment-form', function(e) {
            if ($(this).hasClass('sbir-reply-form')) {
                // Inline replies are handled by the dedicated reply submit handler below.
                return;
            }
            e.preventDefault();
            var $form = $(this);
            var $editor = $form.find('.sbir-comment-editor');
            if ($editor.length) {
                syncEditor($editor);
            }
            var data = $form.serialize();
            var $drawer = $('#sbir-drawer');

            $.post($form.attr('action') || window.location.href, data)
                .always(function() {
                    refreshDrawerContent($drawer);
                });
        });

        $(document).on('input', '#sbir-drawer form.sbir-comment-form .sbir-comment-editor', function() {
            var $editor = $(this);
            syncEditor($editor);
            toggleCommentButton($editor.closest('form'));
        });
        toggleCommentButton($('#sbir-drawer'));

        // Like toggle (optimistic + persisted via AJAX)
        $(document).on('click', '.sbir-comment-like', function() {
            var $btn = $(this);
            var id = $btn.data('comment-id');
            if (!id || $btn.data('busy')) {
                return;
            }
            $btn.data('busy', true);

            var wasPressed = ($btn.attr('aria-pressed') === 'true');
            var $count = $btn.find('.sbir-like-count');
            var original = parseInt($count.text(), 10) || 0;
            var optimistic = wasPressed ? Math.max(0, original - 1) : original + 1;
            $count.text(optimistic);
            $btn.attr('aria-pressed', wasPressed ? 'false' : 'true');

            $.ajax({
                url: sbir_public.ajax_url,
                type: 'POST',
                data: {
                    action: 'sbir_toggle_comment_like',
                    nonce: sbir_public.nonce,
                    comment_id: id
                },
                success: function(resp) {
                    if (resp && resp.success && resp.data) {
                        $btn.attr('aria-pressed', resp.data.liked ? 'true' : 'false');
                        $count.text(resp.data.count);
                    } else {
                        $btn.attr('aria-pressed', wasPressed ? 'true' : 'false');
                        $count.text(original);
                    }
                },
                error: function() {
                    $btn.attr('aria-pressed', wasPressed ? 'true' : 'false');
                    $count.text(original);
                },
                complete: function() {
                    setTimeout(function() {
                        $btn.removeData('busy');
                    }, 50);
                }
            });
        });

        // Client-side sort (recent/oldest)
        $(document).on('click', '.sbir-sort-btn', function() {
            var $btn = $(this);
            var order = $btn.data('order');
            $btn.addClass('active').siblings('.sbir-sort-btn').removeClass('active');

            var $container = $('#sbir-drawer.open').length ? $('#sbir-drawer') : $('body');
            var $list = $container.find('.sbir-comment-list').first();
            if (!$list.length) {
                return;
            }

            var $items = $list.children('li.comment').get();
            $items.sort(function(a, b) {
                var ta = parseInt($(a).attr('data-comment-time'), 10) || 0;
                var tb = parseInt($(b).attr('data-comment-time'), 10) || 0;
                return order === 'asc' ? ta - tb : tb - ta;
            });

            $.each($items, function(_, li) {
                $list.append(li);
            });
        });

        // Toggle three-dots menu inside comments.
        $(document).on('click', '.sbir-comment-menu', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var $btn = $(this);
            var $wrap = $btn.closest('.sbir-comment-menu-wrap');
            $('.sbir-comment-menu-wrap.open').not($wrap).removeClass('open').find('.sbir-comment-menu').attr('aria-expanded', 'false');
            $wrap.toggleClass('open');
            $btn.attr('aria-expanded', $wrap.hasClass('open') ? 'true' : 'false');
        });

        // Inline reply form.
        $(document).on('click', '#sbir-drawer .sbir-reply-btn', function(e) {
            e.preventDefault();

            var $link = $(this);
            var $commentRow = $link.closest('.sbir-comment-row');
            var $comment = $link.closest('li.comment, li.sbir-comment-card');
            if (!$comment.length || !$commentRow.length) {
                return;
            }

            var commentId = $link.data('comment-id') || $comment.data('comment-id') || $comment.attr('id').replace('comment-', '');
            $('#sbir-drawer .sbir-inline-reply').remove();

            var $mainForm = $('#sbir-drawer .sbir-comment-form-card form.sbir-comment-form');
            var postId = $mainForm.find('input[name="comment_post_ID"]').val();
            var actionUrl = $mainForm.attr('action') || window.location.href;
            var formId = 'sbir-reply-form-' + commentId;
            var textareaId = 'sbir-reply-textarea-' + commentId;

            var $mainAvatar = $('#sbir-drawer .sbir-comment-form-card .sbir-form-avatar-wrap').html();
            var avatarHtml = $mainAvatar || '<div class="sbir-avatar-placeholder"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>';

            var replyHtml = '<div class="sbir-inline-reply">' +
                '<div class="sbir-reply-form-card">' +
                    '<div class="sbir-comment-form-top">' +
                        '<div class="sbir-form-avatar-wrap">' + avatarHtml + '</div>' +
                        '<form id="' + formId + '" class="sbir-comment-form sbir-reply-form" action="' + actionUrl + '" method="post">' +
                            '<input type="hidden" name="comment_post_ID" value="' + postId + '">' +
                            '<input type="hidden" name="comment_parent" value="' + commentId + '">' +
                            '<div class="sbir-comment-input-wrap">' +
                                '<div class="sbir-comment-editor" contenteditable="true" data-placeholder="' + t('write_reply_placeholder', 'Write a reply...') + '" data-target="' + textareaId + '"></div>' +
                                '<textarea id="' + textareaId + '" name="comment" rows="2" class="sbir-comment-textarea sbir-hidden-textarea" required></textarea>' +
                            '</div>' +
                        '</form>' +
                    '</div>' +
                    '<div class="sbir-comment-toolbar">' +
                        '<div class="sbir-toolbar-left">' +
                            '<button type="button" class="sbir-toolbar-btn" data-action="bold" title="' + t('bold', 'Bold') + '"><strong>B</strong></button>' +
                            '<button type="button" class="sbir-toolbar-btn" data-action="italic" title="' + t('italic', 'Italic') + '"><em>I</em></button>' +
                            '<button type="button" class="sbir-toolbar-btn" data-action="underline" title="' + t('underline', 'Underline') + '"><span style="text-decoration: underline;">U</span></button>' +
                            '<button type="button" class="sbir-toolbar-btn" data-action="link" title="' + t('link', 'Link') + '"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></button>' +
                        '</div>' +
                        '<div class="sbir-toolbar-right">' +
                            '<button type="button" class="sbir-btn sbir-btn-ghost sbir-cancel-reply">' + t('cancel', 'Cancel') + '</button>' +
                            '<button type="submit" form="' + formId + '" class="sbir-btn sbir-btn-primary sbir-publish-btn">' + t('reply', 'Reply') + '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>';

            $commentRow.after(replyHtml);
            $('#sbir-drawer textarea#comment').prop('disabled', false);
            $('#' + textareaId).trigger('focus');
        });

        $(document).on('click', '#sbir-drawer .sbir-cancel-reply', function(e) {
            e.preventDefault();
            $(this).closest('.sbir-inline-reply').remove();
        });

        // Toolbar actions.
        $(document).on('click', '#sbir-drawer .sbir-toolbar-btn[data-action]', function(e) {
            e.preventDefault();
            var action = $(this).data('action');
            var $container = $(this).closest('form, .sbir-reply-form-card, .sbir-inline-edit, .sbir-comment-form-card');
            var $editor = $container.find('.sbir-comment-editor').first();
            applyTextFormat($editor, action);
        });

        $(document).on('input', '#sbir-drawer .sbir-comment-editor', function() {
            syncEditor($(this));
        });

        $(document).on('blur', '#sbir-drawer .sbir-comment-editor', function() {
            normalizeLinks($(this));
            syncEditor($(this));
        });

        $(document).on('submit', '#sbir-drawer .sbir-reply-form', function(e) {
            e.preventDefault();
            var $form = $(this);
            var $drawer = $('#sbir-drawer');
            var $editor = $form.find('.sbir-comment-editor');
            if ($editor.length) {
                syncEditor($editor);
            }

            $.post($form.attr('action'), $form.serialize())
                .always(function() {
                    refreshDrawerContent($drawer);
                });
        });

        // Close comment popover on outside click or ESC.
        $(document).on('click keydown', function(e) {
            if (e.type === 'keydown' && e.key !== 'Escape') {
                return;
            }
            $('.sbir-comment-menu-wrap.open').removeClass('open').find('.sbir-comment-menu').attr('aria-expanded', 'false');
        });

        // Inline edit comment (owner/moderator)
        $(document).on('click', '.sbir-comment-edit', function() {
            var commentId = $(this).data('comment-id');
            var $card = $('#comment-' + commentId);
            var $content = $card.find('.sbir-comment-content');
            var current = $.trim($content.text());
            var formHtml = '<form class="sbir-inline-edit" data-comment-id="' + commentId + '">\
                <div class="sbir-comment-input-wrap">\
                    <div class="sbir-comment-editor" contenteditable="true" data-placeholder="' + t('edit_comment_placeholder', 'Edit comment...') + '" data-target="sbir-inline-edit-' + commentId + '">' + $('<div>').text(current).html() + '</div>\
                    <textarea id="sbir-inline-edit-' + commentId + '" class="sbir-textarea sbir-hidden-textarea" rows="4"></textarea>\
                </div>\
                <div class="sbir-comment-toolbar">\
                    <div class="sbir-toolbar-left">\
                        <button type="button" class="sbir-toolbar-btn" data-action="bold" title="' + t('bold', 'Bold') + '"><strong>B</strong></button>\
                        <button type="button" class="sbir-toolbar-btn" data-action="italic" title="' + t('italic', 'Italic') + '"><em>I</em></button>\
                        <button type="button" class="sbir-toolbar-btn" data-action="underline" title="' + t('underline', 'Underline') + '"><span style="text-decoration: underline;">U</span></button>\
                        <button type="button" class="sbir-toolbar-btn" data-action="link" title="' + t('link', 'Link') + '"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></button>\
                    </div>\
                    <div class="sbir-toolbar-right">\
                        <button type="button" class="sbir-btn sbir-btn-ghost sbir-cancel-edit">' + t('cancel', 'Cancel') + '</button>\
                        <button type="submit" class="sbir-btn sbir-btn-primary">' + t('save', 'Save') + '</button>\
                    </div>\
                </div>\
            </form>';
            $content.data('orig-html', $content.html()).html(formHtml);
            $card.find('.sbir-comment-menu-wrap').removeClass('open');
        });

        $(document).on('click', '.sbir-cancel-edit', function() {
            var $form = $(this).closest('.sbir-inline-edit');
            var id = $form.data('comment-id');
            var $content = $('#comment-' + id + ' .sbir-comment-content');
            $content.html($content.data('orig-html'));
        });

        $(document).on('submit', '.sbir-inline-edit', function(e) {
            e.preventDefault();
            var $form = $(this);
            var id = $form.data('comment-id');
            var content = $form.find('textarea').val();
            $.post(sbir_public.ajax_url, {
                action: 'sbir_edit_comment',
                nonce: sbir_public.nonce,
                comment_id: id,
                content: content
            }).done(function(resp) {
                if (resp && resp.success) {
                    refreshDrawerContent($('#sbir-drawer'));
                }
            });
        });

        // Delete comment (owner/moderator)
        $(document).on('click', '.sbir-comment-delete', function() {
            var id = $(this).data('comment-id');
            if (!confirm(t('delete_comment_confirm', 'Delete this comment?'))) {
                return;
            }
            $.post(sbir_public.ajax_url, {
                action: 'sbir_delete_comment',
                nonce: sbir_public.nonce,
                comment_id: id
            }).done(function(resp) {
                if (resp && resp.success) {
                    refreshDrawerContent($('#sbir-drawer'));
                }
            });
        });
    });
})(jQuery);
