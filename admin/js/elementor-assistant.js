(function ($) {
    'use strict';

    var observer = null;
    var isActive = false;
    var remountTimer = 0;
    var visibleSuggestionLimit = 3;

    function esc(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function ilsmElementorFormat(template, value) {
        return String(template || '').replace('%s', String(value));
    }

    function postId() {
        if (window.elementor && elementor.config && elementor.config.document) {
            return parseInt(elementor.config.document.id, 10) || 0;
        }
        var params = new URLSearchParams(window.location.search);
        return parseInt(params.get('post'), 10) || 0;
    }

    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', 'readonly');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        area.remove();
        return Promise.resolve();
    }

    function removeLegacyAssistant() {
        $('#ilsm-elementor-assistant, .ilsm-elementor-assistant, .ilsm-el-toggle, .ilsm-el-panel').remove();
        document.body.classList.add('ilsm-no-floating-assistant');
    }

    function elementsPanel() {
        var page = document.querySelector('#elementor-panel-page-elements');
        if (page) {
            return page;
        }

        var navigation = document.querySelector('#elementor-panel-elements-navigation');
        return navigation ? navigation.closest('[id^="elementor-panel-page-"]') : null;
    }

    function navigationRoot() {
        var navigation = document.querySelector('#elementor-panel-elements-navigation');
        if (!navigation) {
            return null;
        }
        return navigation.querySelector('.elementor-panel-navigation-tabs') || navigation;
    }

    function insertionReference(nav) {
        var tabs = Array.prototype.slice.call(
            nav.querySelectorAll('[role="tab"], .elementor-panel-navigation-tab, .elementor-component-tab')
        );
        var seoTab = tabs.find(function (tab) {
            return /(^|\s)seo(\s|$)/i.test((tab.textContent || '').trim());
        });
        return seoTab || tabs[tabs.length - 1] || null;
    }

    function buildTab(nav) {
        var existing = document.getElementById('ilsm-elementor-tab');
        if (existing) {
            return existing;
        }

        var reference = insertionReference(nav);
        var tagName = reference && /^(BUTTON|A|DIV|LI)$/i.test(reference.tagName) ? reference.tagName.toLowerCase() : 'button';
        var tab = document.createElement(tagName);
        tab.id = 'ilsm-elementor-tab';
        tab.className = reference ? reference.className : 'elementor-panel-navigation-tab';
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-selected', 'false');
        tab.setAttribute('aria-controls', 'ilsm-elementor-tab-panel');
        tab.setAttribute('tabindex', '0');
        if ('button' === tagName) {
            tab.setAttribute('type', 'button');
        }
        tab.innerHTML = '<span class="ilsm-elementor-tab-label">InternLink</span>';

        if (reference && reference.nextSibling) {
            nav.insertBefore(tab, reference.nextSibling);
        } else {
            nav.appendChild(tab);
        }
        return tab;
    }

    function panelMarkup() {
        return '' +
            '<div class="ilsm-el-sidebar">' +
                '<header class="ilsm-el-sidebar-head">' +
                    '<span class="ilsm-el-sidebar-icon"><span class="fa fa-link" aria-hidden="true"></span></span>' +
                    '<div><strong>' + esc(ILSM_ELEMENTOR.strings.title) + '</strong><small>' + esc(ILSM_ELEMENTOR.strings.subtitle) + '</small></div>' +
                '</header>' +
                '<div class="ilsm-el-sidebar-body">' +
                    '<div class="ilsm-el-saved-note"><span class="fa fa-shield" aria-hidden="true"></span><p>' + esc(ILSM_ELEMENTOR.strings.saved) + '</p></div>' +
                    '<div class="ilsm-el-support"><strong>' + esc(ILSM_ELEMENTOR.strings.supportedTitle) + '</strong><p>' + esc(ILSM_ELEMENTOR.strings.supported) + '</p></div>' +
                    '<button type="button" class="elementor-button elementor-button-success ilsm-el-analyse"><span class="fa fa-search" aria-hidden="true"></span> ' + esc(ILSM_ELEMENTOR.strings.analyse) + '</button>' +
                    '<div class="ilsm-el-status" aria-live="polite"></div>' +
                    '<div class="ilsm-el-results"></div>' +
                '</div>' +
            '</div>';
    }

    function buildPanel(elements) {
        var existing = document.getElementById('ilsm-elementor-tab-panel');
        if (existing) {
            return existing;
        }
        var panel = document.createElement('section');
        panel.id = 'ilsm-elementor-tab-panel';
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', 'ilsm-elementor-tab');
        panel.setAttribute('aria-hidden', 'true');
        panel.hidden = true;
        panel.innerHTML = panelMarkup();
        elements.appendChild(panel);
        return panel;
    }

    function contentSiblings(elements) {
        var nav = document.querySelector('#elementor-panel-elements-navigation');
        return Array.prototype.filter.call(elements.children, function (child) {
            return child !== nav && child.id !== 'ilsm-elementor-tab-panel';
        });
    }

    function activate() {
        var elements = elementsPanel();
        var tab = document.getElementById('ilsm-elementor-tab');
        var panel = document.getElementById('ilsm-elementor-tab-panel');
        if (!elements || !tab || !panel) {
            return;
        }

        isActive = true;
        document.body.classList.add('ilsm-elementor-tab-active');

        contentSiblings(elements).forEach(function (child) {
            if (!child.hasAttribute('data-ilsm-original-display')) {
                child.setAttribute('data-ilsm-original-display', child.style.display || '');
            }
            child.style.display = 'none';
            child.setAttribute('aria-hidden', 'true');
        });

        panel.hidden = false;
        panel.style.display = 'block';
        panel.setAttribute('aria-hidden', 'false');
        tab.setAttribute('aria-selected', 'true');
        tab.classList.add('active', 'elementor-active');

        var nav = navigationRoot();
        if (nav) {
            nav.querySelectorAll('[role="tab"], .elementor-panel-navigation-tab, .elementor-component-tab').forEach(function (item) {
                if (item !== tab) {
                    item.classList.remove('active', 'elementor-active');
                    item.setAttribute('aria-selected', 'false');
                }
            });
        }
    }

    function deactivate() {
        var elements = elementsPanel();
        var tab = document.getElementById('ilsm-elementor-tab');
        var panel = document.getElementById('ilsm-elementor-tab-panel');

        isActive = false;
        document.body.classList.remove('ilsm-elementor-tab-active');

        if (elements) {
            contentSiblings(elements).forEach(function (child) {
                child.style.display = child.getAttribute('data-ilsm-original-display') || '';
                child.removeAttribute('data-ilsm-original-display');
                child.removeAttribute('aria-hidden');
            });
        }
        if (panel) {
            panel.hidden = true;
            panel.style.display = 'none';
            panel.setAttribute('aria-hidden', 'true');
        }
        if (tab) {
            tab.classList.remove('active', 'elementor-active');
            tab.setAttribute('aria-selected', 'false');
        }
    }

    function dedupeItems(items) {
        var seen = Object.create(null);
        return (items || []).filter(function (item) {
            var key = String(item.post_id || item.url || item.title || '').toLocaleLowerCase();
            if (!key || seen[key]) { return false; }
            seen[key] = true;
            return true;
        });
    }

    function preflightAnchor(item, anchor) {
        var sourceId = postId();
        var targetId = parseInt(item.post_id, 10) || 0;
        if (!sourceId || !targetId || !anchor) {
            return $.Deferred().reject().promise();
        }
        return $.post(ILSM_ELEMENTOR.ajaxUrl, {
            action: 'ilsm_elementor_preview_link',
            nonce: ILSM_ELEMENTOR.nonce,
            source_post_id: sourceId,
            target_post_id: targetId,
            anchor: anchor
        });
    }

    function preflightItem(item) {
        var deferred = $.Deferred();
        var anchors = item.anchors && item.anchors.length ? item.anchors.slice(0) : [item.title];
        var index = 0;

        function next() {
            if (index >= anchors.length) {
                deferred.reject();
                return;
            }
            var anchor = String(anchors[index++] || '').trim();
            if (!anchor) {
                next();
                return;
            }
            preflightAnchor(item, anchor).done(function (response) {
                if (response && response.success && response.data && response.data.element_id && response.data.setting_key && ['wysiwyg','textarea'].indexOf(String(response.data.control_type || '')) !== -1) {
                    item.anchors = [anchor];
                    item._preflight = response.data;
                    deferred.resolve(item);
                    return;
                }
                next();
            }).fail(function () {
                next();
            });
        }

        next();
        return deferred.promise();
    }

    function preflightSuggestions(items) {
        var deferred = $.Deferred();
        var minimum = Math.max(60, Math.min(100, parseInt(ILSM_ELEMENTOR.minConfidence, 10) || 70));
        var queue = dedupeItems(items).filter(function (item) {
            return (parseInt(item.score, 10) || 0) >= minimum;
        }).slice(0);
        var verified = [];
        var index = 0;

        function next() {
            if (index >= queue.length) {
                deferred.resolve(verified);
                return;
            }
            preflightItem(queue[index++]).done(function (item) {
                verified.push(item);
            }).always(next);
        }

        next();
        return deferred.promise();
    }

    function setCardStatus(card, type, message) {
        var $card = $(card);
        var $state = $card.find('.ilsm-el-card-state');
        $state.removeClass('is-success is-error is-info').addClass('is-' + type).text(message || '');
        if ('success' === type) {
            $card.addClass('is-inserted');
        } else if ('error' === type) {
            $card.removeClass('is-inserted');
            var $insert = $card.find('.ilsm-el-insert');
            if ($insert.length && !$insert.prop('disabled')) {
                $insert.html('<span class="fa fa-refresh" aria-hidden="true"></span> ' + esc(ILSM_ELEMENTOR.strings.retry || 'Retry'));
            }
        }
    }

    function render(items) {
        var $results = $('#ilsm-elementor-tab-panel .ilsm-el-results').empty();
        items = dedupeItems(items);
        if (!items.length) {
            $results.html('<div class="ilsm-el-empty"><span class="fa fa-check-circle" aria-hidden="true"></span><div><strong>' + esc(ILSM_ELEMENTOR.strings.noneTitle) + '</strong><p>' + esc(ILSM_ELEMENTOR.strings.none) + '</p></div></div>');
            return;
        }

        var initialVisible = Math.min(visibleSuggestionLimit, items.length);
        $results.append('<div class="ilsm-el-results-summary"><strong>' + initialVisible + '</strong><span> '+esc(ILSM_ELEMENTOR.strings.bestSuggestionsShown)+'</span></div>');
        items.forEach(function (item, index) {
            var anchors = item.anchors && item.anchors.length ? item.anchors : [item.title];
            var options = anchors.map(function (anchor) {
                return '<option value="' + esc(anchor) + '">' + esc(anchor) + '</option>';
            }).join('');
            var terms = (item.shared_terms || []).slice(0, 4).map(function (term) {
                return '<span>' + esc(term) + '</span>';
            }).join('');
            var score = Math.max(0, Math.min(100, parseInt(item.score, 10) || 0));
            $results.append('<article class="ilsm-el-card' + (index >= visibleSuggestionLimit ? ' ilsm-el-card-extra' : '') + '"' + (index >= visibleSuggestionLimit ? ' hidden' : '') + ' data-target-id="' + parseInt(item.post_id, 10) + '" data-element-id="' + esc(item._preflight && item._preflight.element_id ? item._preflight.element_id : '') + '" data-setting-key="' + esc(item._preflight && item._preflight.setting_key ? item._preflight.setting_key : '') + '" data-control-type="' + esc(item._preflight && item._preflight.control_type ? item._preflight.control_type : '') + '" data-url="' + esc(item.url) + '">' +
                '<div class="ilsm-el-card-head"><span class="ilsm-el-card-number">' + (index + 1) + '</span><strong>' + esc(item.title) + '</strong><b>' + score + '%</b></div>' +
                '<div class="ilsm-el-score"><span style="width:' + score + '%"></span></div>' +
                '<p class="ilsm-el-reason">' + esc(item.reason) + '</p><div class="ilsm-el-terms">' + terms + '</div>' +
                '<label>' + esc(ILSM_ELEMENTOR.strings.anchorLabel) + '<select class="ilsm-el-anchor">' + options + '</select></label>' +
                '<div class="ilsm-el-card-state is-info">'+esc(ILSM_ELEMENTOR.strings.readySafeInsertion)+'</div>' +
                '<div class="ilsm-el-card-actions">' +
                    (ILSM_ELEMENTOR.canInsert ? '<button type="button" class="elementor-button elementor-button-success ilsm-el-insert"><span class="fa fa-link" aria-hidden="true"></span> ' + esc(ILSM_ELEMENTOR.strings.insert) + '</button>' : '') +
                    '<button type="button" class="ilsm-el-ignore"><span class="fa fa-ban" aria-hidden="true"></span> ' + esc(ILSM_ELEMENTOR.strings.ignore) + '</button>' +
                '</div>' +
            '</article>');
        });
        if (items.length > visibleSuggestionLimit) {
            $results.append('<button type="button" class="elementor-button ilsm-el-show-more">' + esc(ilsmElementorFormat(ILSM_ELEMENTOR.strings.showMoreSuggestions, items.length - visibleSuggestionLimit)) + '</button>');
        }
    }

    function collectLiveExistingUrls() {
        var urls = [];
        var seen = Object.create(null);
        try {
            var frame = document.querySelector('#elementor-preview-iframe');
            var doc = frame && frame.contentDocument ? frame.contentDocument : null;
            if (!doc) { return urls; }
            Array.prototype.forEach.call(doc.querySelectorAll('a[href]'), function (link) {
                var href = '';
                try { href = String(link.href || link.getAttribute('href') || '').trim(); } catch (e) {}
                if (!href) { return; }
                var key = href.replace(/\/$/, '').toLowerCase();
                if (!seen[key]) { seen[key] = true; urls.push(href); }
            });
        } catch (e) {}
        return urls.slice(0, 1000);
    }

    function analyse(button) {
        var id = postId();
        var $button = $(button);
        var $status = $('#ilsm-elementor-tab-panel .ilsm-el-status').text(ILSM_ELEMENTOR.strings.loading);
        if (!id) {
            $status.text(ILSM_ELEMENTOR.strings.error);
            return;
        }
        $button.prop('disabled', true);
        $.post(ILSM_ELEMENTOR.ajaxUrl, {
            action: 'ilsm_local_suggestions',
            nonce: ILSM_ELEMENTOR.nonce,
            post_id: id,
            live_existing_urls: JSON.stringify(collectLiveExistingUrls())
        }).done(function (response) {
            if (!response || !response.success) {
                var message = response && response.data && response.data.message ? response.data.message : ILSM_ELEMENTOR.strings.error;
                $status.text(message);
                return;
            }
            $status.text(ILSM_ELEMENTOR.strings.checkingSafe);
            preflightSuggestions(response.data.suggestions || []).done(function (verified) {
                render(verified);
                if (verified.length) {
                    $status.text(ilsmElementorFormat(1 === verified.length ? ILSM_ELEMENTOR.strings.verifiedReadyOne : ILSM_ELEMENTOR.strings.verifiedReadyMany, verified.length));
                } else {
                    var minimum = Math.max(60, Math.min(100, parseInt(ILSM_ELEMENTOR.minConfidence, 10) || 70));
                    $status.text(ilsmElementorFormat(ILSM_ELEMENTOR.strings.noneAtConfidence, minimum));
                }
            });
        }).fail(function (xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
            $status.text(message || ILSM_ELEMENTOR.strings.error);
        }).always(function () {
            $button.prop('disabled', false);
        });
    }

    function mount() {
        removeLegacyAssistant();
        var nav = navigationRoot();
        var elements = elementsPanel();
        if (!nav || !elements) {
            return false;
        }

        var tab = buildTab(nav);
        buildPanel(elements);

        if (!tab.dataset.ilsmBound) {
            tab.dataset.ilsmBound = '1';
            tab.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                activate();
            });
            tab.addEventListener('keydown', function (event) {
                if ('Enter' === event.key || ' ' === event.key) {
                    event.preventDefault();
                    activate();
                }
            });
        }

        if (isActive) {
            activate();
        }
        return true;
    }

    function scheduleMount() {
        window.clearTimeout(remountTimer);
        remountTimer = window.setTimeout(mount, 40);
    }

    $(document).on('click', '#elementor-panel-elements-navigation [role="tab"], #elementor-panel-elements-navigation .elementor-panel-navigation-tab, #elementor-panel-elements-navigation .elementor-component-tab', function () {
        if ('ilsm-elementor-tab' !== this.id) {
            deactivate();
        }
    });

    $(document).on('click', '.ilsm-el-analyse', function () {
        analyse(this);
    });

    function previewDocument() {
        try {
            if (window.elementor && elementor.$previewContents && elementor.$previewContents.length) {
                return elementor.$previewContents[0].ownerDocument || elementor.$previewContents[0];
            }
            var frame = document.querySelector('#elementor-preview-iframe, iframe[name="elementor-preview-iframe"], #elementor-preview iframe');
            return frame && frame.contentDocument ? frame.contentDocument : null;
        } catch (e) {
            return null;
        }
    }


    function previewElementByDataId(doc, elementId) {
        if (!doc || !doc.body || !elementId) { return null; }
        var expectedId = String(elementId);
        var candidates = doc.querySelectorAll('[data-id]');
        for (var i = 0; i < candidates.length; i++) {
            if (String(candidates[i].getAttribute('data-id') || '') === expectedId) {
                return candidates[i];
            }
        }
        return null;
    }

    function getElementorContainer(elementId) {
        try {
            if (window.elementor && typeof elementor.getContainer === 'function') {
                return elementor.getContainer(String(elementId));
            }
        } catch (e) {}
        return null;
    }

    function openElementorTextControl(elementId) {
        var doc = previewDocument();
        var container = getElementorContainer(elementId);
        var target = null;

        if (doc && doc.body && elementId) {
            target = previewElementByDataId(doc, elementId);
            if (target) {
                target.scrollIntoView({behavior: 'smooth', block: 'center'});
                var previousOutline = target.style.outline;
                var previousOffset = target.style.outlineOffset;
                target.classList.add('ilsm-elementor-preview-hit');
                target.style.outline = '3px solid #22c55e';
                target.style.outlineOffset = '4px';
                window.setTimeout(function () {
                    target.classList.remove('ilsm-elementor-preview-hit');
                    target.style.outline = previousOutline;
                    target.style.outlineOffset = previousOffset;
                }, 3200);
            }
        }

        if (container && container.model && container.view && window.$e && typeof $e.run === 'function') {
            try {
                $e.run('panel/editor/open', {
                    model: container.model,
                    view: container.view
                });
            } catch (e) {}
        }
        return !!(container || target);
    }

    function resolveTextControlLocation(card) {
        var anchor = String(card.find('.ilsm-el-anchor').val() || '').trim();
        var sourceId = postId();
        var targetId = parseInt(card.attr('data-target-id'), 10) || 0;
        if (!anchor || !sourceId || !targetId) {
            return $.Deferred().reject({message: ILSM_ELEMENTOR.strings.previewMissing}).promise();
        }
        return $.post(ILSM_ELEMENTOR.ajaxUrl, {
            action: 'ilsm_elementor_preview_link',
            nonce: ILSM_ELEMENTOR.nonce,
            source_post_id: sourceId,
            target_post_id: targetId,
            anchor: anchor
        });
    }

    function previewAnchor(card) {
        var $status = $('#ilsm-elementor-tab-panel .ilsm-el-status');
        $status.text(ILSM_ELEMENTOR.strings.loading);
        resolveTextControlLocation(card).done(function (response) {
            if (!response || !response.success || !response.data) {
                var message = response && response.data && response.data.message ? response.data.message : ILSM_ELEMENTOR.strings.previewMissing;
                $status.text(message);
                return;
            }
            var elementId = response.data.element_id || '';
            card.attr('data-element-id', elementId);
            if (openElementorTextControl(elementId)) {
                setCardStatus(card, 'info', 'Text control located. Elementor opened the exact widget.');
                $status.text(ILSM_ELEMENTOR.strings.previewFound);
            } else {
                setCardStatus(card, 'error', ILSM_ELEMENTOR.strings.previewMissing);
                $status.text(ILSM_ELEMENTOR.strings.previewMissing);
            }
        }).fail(function (xhr) {
            var message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
            message = message || ILSM_ELEMENTOR.strings.previewMissing;
            setCardStatus(card, 'error', message);
            $status.text(message);
        });
    }

    $(document).on('click', '.ilsm-el-preview', function () {
        previewAnchor($(this).closest('.ilsm-el-card'));
    });

    function renderedWidgetHasLink(elementId, targetUrl) {
        var doc = previewDocument();
        if (!doc || !doc.body || !elementId || !targetUrl) { return false; }
        var widget = previewElementByDataId(doc, elementId);
        if (!widget) { return false; }
        var links = widget.querySelectorAll('a[href]');
        for (var i = 0; i < links.length; i++) {
            try {
                if (new URL(links[i].href, window.location.href).href === new URL(targetUrl, window.location.href).href) { return true; }
            } catch (e) {
                if (String(links[i].getAttribute('href') || '') === String(targetUrl)) { return true; }
            }
        }
        return false;
    }


    function waitForRenderedLink(elementId, targetUrl) {
        var deferred = $.Deferred();
        var attempts = 0;
        var maxAttempts = 16;
        function check() {
            attempts++;
            if (renderedWidgetHasLink(elementId, targetUrl)) {
                deferred.resolve();
                return;
            }
            if (attempts >= maxAttempts) {
                deferred.reject();
                return;
            }
            window.setTimeout(check, 180);
        }
        check();
        return deferred.promise();
    }


    function verifyPublishedLink($card, sourcePostId, targetPostId, anchor) {
        return $.ajax({
            url: ILSM_ELEMENTOR.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'ilsm_elementor_verify_published_link',
                nonce: ILSM_ELEMENTOR.nonce,
                source_post_id: sourcePostId,
                target_post_id: targetPostId,
                anchor: anchor
            }
        });
    }

    function renderInsertedActions($card, context) {
        $card.data('ilsmInsertionContext', context);
        var sourceUrl = String(ILSM_ELEMENTOR.sourceUrl || '');
        var html = '';
        if (sourceUrl) {
            html += '<a class="elementor-button ilsm-el-view-live" href="' + esc(sourceUrl) + '" target="_blank" rel="noopener noreferrer"><span class="fa fa-external-link" aria-hidden="true"></span> ' + esc(ILSM_ELEMENTOR.strings.viewLive || 'View live page') + '</a>';
        }
        html += '<button type="button" class="elementor-button ilsm-el-edit-inserted"><span class="fa fa-pencil" aria-hidden="true"></span> ' + esc(ILSM_ELEMENTOR.strings.editInserted || 'Edit') + '</button>';
        html += '<button type="button" class="elementor-button ilsm-el-undo-insert"><span class="fa fa-undo" aria-hidden="true"></span> ' + esc(ILSM_ELEMENTOR.strings.undo || 'Undo') + '</button>';
        $card.find('.ilsm-el-card-actions').html(html);
    }

    function restoreReadyActions($card) {
        var html = '';
        if (ILSM_ELEMENTOR.canInsert) {
            html += '<button type="button" class="elementor-button elementor-button-success ilsm-el-insert"><span class="fa fa-link" aria-hidden="true"></span> ' + esc(ILSM_ELEMENTOR.strings.insert) + '</button>';
        }
        html += '<button type="button" class="ilsm-el-ignore"><span class="fa fa-ban" aria-hidden="true"></span> ' + esc(ILSM_ELEMENTOR.strings.ignore) + '</button>';
        $card.find('.ilsm-el-card-actions').html(html);
    }

    function hasUnsavedElementorChanges() {
        try {
            if (window.elementor && elementor.saver && typeof elementor.saver.isEditorChanged === 'function') {
                return !!elementor.saver.isEditorChanged();
            }
            if (window.elementor && elementor.documents && elementor.documents.getCurrent) {
                var documentModel = elementor.documents.getCurrent();
                if (documentModel && documentModel.container && documentModel.container.model) {
                    return !!documentModel.container.model.get('isChanged');
                }
            }
        } catch (e) {}
        return false;
    }

    $(document).on('click', '.ilsm-el-insert', function () {
        var $button = $(this);
        var $card = $button.closest('.ilsm-el-card');
        var anchor = String($card.find('.ilsm-el-anchor').val() || '').trim();
        var $status = $('#ilsm-elementor-tab-panel .ilsm-el-status');

        if (hasUnsavedElementorChanges()) {
            setCardStatus($card, 'error', ILSM_ELEMENTOR.strings.insertionBlockedSave);
            $status.text(ILSM_ELEMENTOR.strings.unsaved);
            return;
        }
        if (!anchor || !window.confirm(ILSM_ELEMENTOR.strings.confirmInsert)) {
            return;
        }
        if (!window.$e || typeof $e.run !== 'function' || !window.elementor || !elementor.documents || !elementor.documents.getCurrent) {
            setCardStatus($card, 'error', 'Elementor editor API is unavailable. Reload Elementor and try again.');
            $status.text(ILSM_ELEMENTOR.strings.editorApiUnavailable);
            return;
        }

        $button.prop('disabled', true).addClass('is-loading');
        setCardStatus($card, 'info', 'Inserting and saving with Elementor…');
        $status.text(ILSM_ELEMENTOR.strings.inserting);

        resolveTextControlLocation($card).done(function (response) {
            if (!response || !response.success || !response.data) {
                var message = response && response.data && response.data.message ? response.data.message : ILSM_ELEMENTOR.strings.error;
                setCardStatus($card, 'error', 'Insertion failed: ' + message);
                $status.text(message);
                $button.prop('disabled', false).removeClass('is-loading');
                return;
            }

            var data = response.data;
            var elementId = String(data.element_id || '');
            var container = getElementorContainer(elementId);
            if (!container || !container.settings || typeof container.settings.get !== 'function') {
                setCardStatus($card, 'error', 'The exact Elementor text widget could not be opened. Analyse again.');
                $status.text(ILSM_ELEMENTOR.strings.widgetOpenFailed);
                $button.prop('disabled', false).removeClass('is-loading');
                return;
            }

            var settingKey = String(data.setting_key || '');
            var controlType = String(data.control_type || '');
            if (!settingKey || ['wysiwyg','textarea'].indexOf(controlType) === -1) {
                setCardStatus($card, 'error', 'Not insertable: Elementor no longer reports a safe textarea/WYSIWYG control.');
                $status.text(ILSM_ELEMENTOR.strings.controlUnsupported);
                $button.prop('disabled', false).removeClass('is-loading');
                return;
            }

            var currentHtml = String(container.settings.get(settingKey) || '');
            var originalHtml = String(data.original_html || '');
            var newHtml = String(data.new_html || '');
            if (!newHtml || !originalHtml) {
                setCardStatus($card, 'error', 'Insertion failed: DMA InternLink Mapper could not prepare a safe Elementor text-control update.');
                $status.text(ILSM_ELEMENTOR.strings.prepareUpdateFailed);
                $button.prop('disabled', false).removeClass('is-loading');
                return;
            }
            if (currentHtml !== originalHtml) {
                setCardStatus($card, 'error', 'Insertion stopped: the Elementor text control changed after analysis.');
                $status.text(ILSM_ELEMENTOR.strings.controlChanged);
                openElementorTextControl(elementId);
                $button.prop('disabled', false).removeClass('is-loading');
                return;
            }

            try {
                $e.run('document/elements/settings', {
                    container: container,
                    settings: (function(){ var update={}; update[settingKey]=newHtml; return update; })()
                });
            } catch (e) {
                setCardStatus($card, 'error', 'Insertion failed: Elementor rejected the text-control update.');
                $status.text(ILSM_ELEMENTOR.strings.updateRejected);
                $button.prop('disabled', false).removeClass('is-loading');
                return;
            }

            openElementorTextControl(elementId);
            var documentModel = elementor.documents.getCurrent();
            var saveResult;
            try {
                saveResult = $e.run('document/save/update', { document: documentModel });
            } catch (e) {
                try {
                    $e.run('document/elements/settings', { container: container, settings: (function(){ var update={}; update[settingKey]=originalHtml; return update; })() });
                } catch (rollbackError) {}
                setCardStatus($card, 'error', 'Insertion failed: Elementor could not save. The change was reverted.');
                $status.text(ILSM_ELEMENTOR.strings.saveStartFailed);
                $button.prop('disabled', false).removeClass('is-loading');
                return;
            }

            $.when(saveResult).done(function () {
                var savedHtml = String(container.settings.get(settingKey) || '');
                var targetUrl = String(data.target_url || '');
                if (savedHtml.indexOf('data-ilsm-insertion="1"') === -1 || (targetUrl && savedHtml.indexOf(targetUrl) === -1)) {
                    setCardStatus($card, 'error', 'Insertion failed verification. The link was not confirmed.');
                    $status.text(ILSM_ELEMENTOR.strings.liveWidgetVerifyFailed);
                    $button.prop('disabled', false).removeClass('is-loading');
                    return;
                }
                waitForRenderedLink(elementId, targetUrl).done(function () {
                    var sourcePostId = postId();
                    var targetPostId = parseInt($card.attr('data-target-id'), 10) || 0;
                    verifyPublishedLink($card, sourcePostId, targetPostId, anchor).done(function (verifyResponse) {
                        var verify = verifyResponse && verifyResponse.success ? (verifyResponse.data || {}) : {};
                        if (!verify.persisted) {
                            setCardStatus($card, 'error', 'Elementor reported a save, but the link is not present in persisted Elementor data. No published status was claimed.');
                            $status.text(ILSM_ELEMENTOR.strings.saveVerifyFailed);
                            $button.prop('disabled', false).removeClass('is-loading');
                            return;
                        }
                        $card.addClass('is-inserted');
                        if (verify.published && verify.live) {
                            setCardStatus($card, 'success', ILSM_ELEMENTOR.strings.publishedVerified || 'Published and verified live.');
                            $status.text(ILSM_ELEMENTOR.strings.publishedVerified || 'Published and verified live.');
                        } else if (!verify.published) {
                            setCardStatus($card, 'info', 'Inserted and verified in saved Elementor data. This page is not published, so there is no live link to verify yet.');
                            $status.text(ILSM_ELEMENTOR.strings.publishWhenReady);
                        } else {
                            setCardStatus($card, 'info', ILSM_ELEMENTOR.strings.savedNotLive || 'Saved in Elementor, but the public page could not yet be verified. Update/publish the page and verify again.');
                            $status.text(ILSM_ELEMENTOR.strings.savedNotLive || 'Saved, but live verification is pending.');
                        }
                        openElementorTextControl(elementId);
                        renderInsertedActions($card, {
                            elementId: elementId,
                            settingKey: settingKey,
                            controlType: controlType,
                            originalHtml: originalHtml,
                            insertedHtml: savedHtml,
                            targetUrl: targetUrl
                        });
                    }).fail(function () {
                        setCardStatus($card, 'info', 'The link is visible in Elementor, but server-side publication verification could not be completed. No live status was claimed.');
                        $status.text(ILSM_ELEMENTOR.strings.saveLivePending);
                        $button.prop('disabled', false).removeClass('is-loading');
                    });
                }).fail(function () {
                    try {
                        $e.run('document/elements/settings', { container: container, settings: (function(){ var update={}; update[settingKey]=originalHtml; return update; })() });
                        $e.run('document/save/update', { document: elementor.documents.getCurrent() });
                    } catch (rollbackError) {}
                    setCardStatus($card, 'error', ILSM_ELEMENTOR.strings.renderVerifyFailed || 'Insertion failed: the saved control did not render a live link. The original value was restored.');
                    $status.text(ILSM_ELEMENTOR.strings.renderVerifyFailed || 'The inserted HTML was not rendered as a live link. DMA InternLink Mapper restored the original Elementor value.');
                    $button.prop('disabled', false).removeClass('is-loading').html('<span class="fa fa-refresh" aria-hidden="true"></span> ' + esc(ILSM_ELEMENTOR.strings.retry || 'Retry'));
                });
            }).fail(function () {
                try {
                    $e.run('document/elements/settings', { container: container, settings: (function(){ var update={}; update[settingKey]=originalHtml; return update; })() });
                } catch (rollbackError) {}
                setCardStatus($card, 'error', 'Insertion failed: Elementor could not save. The change was reverted.');
                $status.text(ILSM_ELEMENTOR.strings.saveFailedReverted);
                $button.prop('disabled', false).removeClass('is-loading');
            });
        }).fail(function (xhr) {
            var message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
            message = message || ILSM_ELEMENTOR.strings.error;
            setCardStatus($card, 'error', 'Insertion failed: ' + message);
            $status.text(message);
            $button.prop('disabled', false).removeClass('is-loading');
        });
    });


    $(document).on('click', '.ilsm-el-edit-inserted', function () {
        var $card = $(this).closest('.ilsm-el-card');
        var context = $card.data('ilsmInsertionContext') || {};
        if (!context.elementId || !openElementorTextControl(context.elementId)) {
            setCardStatus($card, 'error', 'The inserted Elementor control could not be reopened.');
            $('#ilsm-elementor-tab-panel .ilsm-el-status').text(ILSM_ELEMENTOR.strings.controlReopenFailed);
        }
    });

    $(document).on('click', '.ilsm-el-undo-insert', function () {
        var $button = $(this);
        var $card = $button.closest('.ilsm-el-card');
        var context = $card.data('ilsmInsertionContext') || {};
        var $status = $('#ilsm-elementor-tab-panel .ilsm-el-status');
        if (!context.elementId || !context.settingKey || typeof context.originalHtml !== 'string') {
            setCardStatus($card, 'error', 'Undo is unavailable because the verified insertion context is missing.');
            return;
        }
        if (hasUnsavedElementorChanges()) {
            setCardStatus($card, 'error', 'Undo blocked: save/update your current Elementor changes first.');
            $status.text(ILSM_ELEMENTOR.strings.unsaved);
            return;
        }
        var container = getElementorContainer(context.elementId);
        if (!container || !container.settings || typeof container.settings.get !== 'function') {
            setCardStatus($card, 'error', 'Undo failed: the Elementor control is no longer available.');
            return;
        }
        var currentHtml = String(container.settings.get(context.settingKey) || '');
        if (currentHtml !== String(context.insertedHtml || '')) {
            setCardStatus($card, 'error', 'Undo stopped: this Elementor text control changed after insertion.');
            openElementorTextControl(context.elementId);
            return;
        }
        $button.prop('disabled', true);
        setCardStatus($card, 'info', 'Undoing the verified insertion…');
        try {
            $e.run('document/elements/settings', { container: container, settings: (function(){ var update={}; update[context.settingKey]=context.originalHtml; return update; })() });
            var saveResult = $e.run('document/save/update', { document: elementor.documents.getCurrent() });
            $.when(saveResult).done(function () {
                if (String(container.settings.get(context.settingKey) || '') !== String(context.originalHtml)) {
                    setCardStatus($card, 'error', 'Undo failed verification.');
                    $button.prop('disabled', false);
                    return;
                }
                $card.removeClass('is-inserted').removeData('ilsmInsertionContext');
                setCardStatus($card, 'info', ILSM_ELEMENTOR.strings.undone || 'Insertion undone. The original Elementor text was restored.');
                $status.text(ILSM_ELEMENTOR.strings.undone || 'Insertion undone.');
                restoreReadyActions($card);
                openElementorTextControl(context.elementId);
            }).fail(function () {
                try {
                    $e.run('document/elements/settings', { container: container, settings: (function(){ var update={}; update[context.settingKey]=context.insertedHtml; return update; })() });
                } catch (rollbackError) {}
                setCardStatus($card, 'error', 'Undo failed: Elementor could not save the restored text.');
                $button.prop('disabled', false);
            });
        } catch (e) {
            setCardStatus($card, 'error', 'Undo failed: Elementor rejected the text-control update.');
            $button.prop('disabled', false);
        }
    });

    $(document).on('click', '.ilsm-el-show-more', function () {
        $('#ilsm-elementor-tab-panel .ilsm-el-card-extra').prop('hidden', false).hide().slideDown(140);
        $(this).remove();
        $('#ilsm-elementor-tab-panel .ilsm-el-results-summary').html('<strong>' + $('#ilsm-elementor-tab-panel .ilsm-el-card').length + '</strong><span> '+esc(ILSM_ELEMENTOR.strings.verifiedSuggestions)+'</span>');
    });

    $(document).on('click', '.ilsm-el-ignore', function () {
        var $card = $(this).closest('.ilsm-el-card');
        $card.slideUp(140, function(){ $(this).remove(); });
    });

    function restoreInsertedLocation() {
        var stored = null;
        try {
            stored = window.sessionStorage.getItem('ilsmElementorInsertedLocation');
            if (!stored) { return; }
            stored = JSON.parse(stored);
        } catch (e) { return; }
        if (!stored || !stored.elementId) { return; }

        var attempts = 0;
        var timer = window.setInterval(function () {
            attempts++;
            if (stored.reopenIlm) { activate(); }
            if (openElementorTextControl(stored.elementId) || attempts > 20) {
                window.clearInterval(timer);
                try { window.sessionStorage.removeItem('ilsmElementorInsertedLocation'); } catch (e) {}
                if (attempts <= 20) {
                    $('#ilsm-elementor-tab-panel .ilsm-el-status').text(ILSM_ELEMENTOR.strings.inserted);
                }
            }
        }, 250);
    }

    function startObserver() {
        if (observer || !window.MutationObserver) {
            return;
        }
        observer = new MutationObserver(function (mutations) {
            var needsMount = mutations.some(function (mutation) {
                return mutation.addedNodes && mutation.addedNodes.length;
            });
            if (needsMount) {
                scheduleMount();
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    $(window).on('elementor:init', function () {
        mount();
        startObserver();
    });

    $(function () {
        removeLegacyAssistant();
        mount();
        startObserver();
    });
})(jQuery);
