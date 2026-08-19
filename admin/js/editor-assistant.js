(function($){
    'use strict';

    function esc(s){ return $('<div>').text(s == null ? '' : String(s)).html(); }
    function compactLabel(text,max){
        text=String(text||'').replace(/\s+/g,' ').trim();
        max=parseInt(max,10)||42;
        if(text.length<=max){ return text; }
        return text.substring(0,Math.max(1,max-1)).trim()+'…';
    }
    function currentPostId(){ return parseInt($('#ilsm-assistant').data('post-id'),10)||0; }
    function normalizeUrl(url){ try { var u=new URL(url,window.location.origin); return (u.origin+u.pathname).replace(/\/$/,'').toLowerCase(); } catch(e){ return String(url||'').replace(/\/$/,'').toLowerCase(); } }
    function linkKind(url){ try { var u=new URL(url,window.location.origin); if(!/^https?:$/.test(u.protocol)){ return ''; } return u.origin===window.location.origin?'internal':'external'; } catch(e){ return ''; } }
    function isValidNaturalAnchor(anchor){
        anchor=String(anchor||'').trim();
        if(!anchor){ return false; }
        try { if(!/^[\p{L}\p{N}]+(?:[\x20\x09\u00a0]+[\p{L}\p{N}]+){0,2}$/u.test(anchor)){ return false; } }
        catch(e){ if(!/^[A-Za-z0-9À-ÖØ-öø-ÿ]+(?:[ \t\u00a0]+[A-Za-z0-9À-ÖØ-öø-ÿ]+){0,2}$/.test(anchor)){ return false; } }
        var words=anchor.toLocaleLowerCase().split(/[ \t\u00a0]+/).filter(Boolean);
        if(!words.length || words.length>3){ return false; }
        var locationOnly={marrakech:1,morocco:1,moroccan:1,city:1,town:1,village:1,country:1,region:1,destination:1,area:1,the:1};
        if(words.every(function(word){ return !!locationOnly[word]; })){ return false; }
        var weakSingle={marrakech:1,morocco:1,moroccan:1,desert:1,adventure:1,travel:1,tour:1,tours:1,trip:1,trips:1,guide:1,blog:1,page:1,post:1,discover:1,explore:1,details:1,city:1};
        return words.length>1 || (anchor.length>=7 && !weakSingle[words[0]]);
    }


    function getEditorBlocks(){
        var blocks=[];
        try {
            if(window.wp && wp.data){
                var blockStore=wp.data.select('core/block-editor');
                if(blockStore && typeof blockStore.getBlocks==='function'){
                    blocks=blockStore.getBlocks()||[];
                }
                if((!blocks || !blocks.length)){
                    var editorStore=wp.data.select('core/editor');
                    if(editorStore && typeof editorStore.getEditorBlocks==='function'){
                        blocks=editorStore.getEditorBlocks()||[];
                    }
                }
            }
        } catch(e){ blocks=[]; }
        if((!blocks || !blocks.length) && window.wp && wp.blocks && typeof wp.blocks.parse==='function'){
            try {
                var content=getEditedPostContent();
                if(content){ blocks=wp.blocks.parse(content)||[]; }
            } catch(e2){ blocks=[]; }
        }
        return blocks||[];
    }

    function getEditedPostContent(){
        try {
            if(window.wp && wp.data){
                var editorStore=wp.data.select('core/editor');
                if(editorStore && typeof editorStore.getEditedPostContent==='function'){
                    return String(editorStore.getEditedPostContent()||'');
                }
                if(editorStore && typeof editorStore.getEditedPostAttribute==='function'){
                    return String(editorStore.getEditedPostAttribute('content')||'');
                }
            }
        } catch(e){}
        return String($('#content').val()||'');
    }

    function editorStoreAvailable(){
        try {
            return !!(window.wp && wp.data && wp.data.select('core/block-editor') && wp.data.dispatch('core/block-editor'));
        } catch(e){ return false; }
    }

    function selectedAnchor(fallback){
        var selected='';
        if(window.getSelection){ selected=String(window.getSelection().toString()||'').trim(); }
        if(!selected && window.tinyMCE && tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden()){
            selected=tinyMCE.activeEditor.selection.getContent({format:'text'}).trim();
        } else if(!selected && document.activeElement && /textarea|input/i.test(document.activeElement.tagName)){
            var el=document.activeElement;
            if(typeof el.selectionStart==='number') selected=el.value.substring(el.selectionStart,el.selectionEnd).trim();
        }
        return selected || fallback || '';
    }

    function createLinkHtml(url,anchor){
        return '<a href="'+esc(url)+'">'+esc(anchor)+'</a>';
    }


    function inspectionRootFromHtml(html){
        try {
            var parsed=(new window.DOMParser()).parseFromString(String(html||''),'text/html');
            return parsed && parsed.body ? parsed.body : null;
        } catch(e){
            return null;
        }
    }

    function replaceTextNodeOnce(html,needle,url){
        if(!html || !needle || /<a\b[^>]*>[\s\S]*?$/i.test('')){ return null; }
        var wrap=inspectionRootFromHtml(html);
        if(!wrap){ return null; }
        var walker=document.createTreeWalker(wrap,NodeFilter.SHOW_TEXT,null);
        var node;
        while((node=walker.nextNode())){
            if(node.parentElement && node.parentElement.closest('a,script,style,code,pre,h1,h2,h3,h4,h5,h6')){ continue; }
            var index=node.nodeValue.toLocaleLowerCase().indexOf(String(needle).toLocaleLowerCase());
            if(index===-1){ continue; }
            var before=node.nodeValue.slice(0,index);
            var matched=node.nodeValue.slice(index,index+needle.length);
            var after=node.nodeValue.slice(index+needle.length);
            var frag=document.createDocumentFragment();
            if(before){ frag.appendChild(document.createTextNode(before)); }
            var a=document.createElement('a'); a.href=url; a.textContent=matched; frag.appendChild(a);
            if(after){ frag.appendChild(document.createTextNode(after)); }
            node.parentNode.replaceChild(frag,node);
            return wrap.innerHTML;
        }
        return null;
    }

    function cssEscape(value){
        if(window.CSS && typeof window.CSS.escape==='function'){ return window.CSS.escape(value); }
        return String(value).replace(/[^a-zA-Z0-9_-]/g,'\\$&');
    }

    function scrollToBlock(clientId,url,anchor){
        if(!clientId){ return false; }
        try {
            if(window.wp && wp.data){ wp.data.dispatch('core/block-editor').selectBlock(clientId); }
            var tries=0;
            function locate(){
                tries++;
                var el=document.querySelector('[data-block="'+cssEscape(clientId)+'"]');
                if(!el && tries<8){ window.setTimeout(locate,100); return; }
                if(!el){ return; }
                el.scrollIntoView({behavior:'smooth',block:'center'});
                el.classList.remove('ilsm-link-location-flash');
                void el.offsetWidth;
                el.classList.add('ilsm-link-location-flash');
                var links=el.querySelectorAll('a[href]'), target=null;
                for(var i=0;i<links.length;i++){
                    var href=normalizeUrl(links[i].getAttribute('href'));
                    var text=String(links[i].textContent||'').trim().toLocaleLowerCase();
                    if((!url || href===normalizeUrl(url)) && (!anchor || text===String(anchor).trim().toLocaleLowerCase())){ target=links[i]; break; }
                }
                if(target){
                    target.classList.remove('ilsm-inserted-link-flash');
                    void target.offsetWidth;
                    target.classList.add('ilsm-inserted-link-flash');
                    window.setTimeout(function(){ target.classList.remove('ilsm-inserted-link-flash'); },2200);
                }
                window.setTimeout(function(){ el.classList.remove('ilsm-link-location-flash'); },2200);
            }
            window.setTimeout(locate,120);
            return true;
        } catch(e){ return false; }
    }

    function isExcludedBlock(blockName){
        blockName=String(blockName||'').toLowerCase();
        return blockName==='core/heading' ||
            /(^|\/)heading$/.test(blockName) ||
            /(^|[-_/])heading($|[-_/])/.test(blockName) ||
            /^(core\/(image|gallery|media-text|cover|video|audio|file|button|buttons|navigation|site-title|post-title|query-title))$/.test(blockName) ||
            /(^|[-_/])(image|gallery|media|photo|slider|carousel|button|navigation|menu|title|heading|caption)($|[-_/])/.test(blockName);
    }

    function attributeHtml(value){
        if(typeof value==='string'){ return value; }
        if(value && typeof value.toHTMLString==='function'){
            try { return String(value.toHTMLString()||''); } catch(e){}
        }
        if(window.wp && wp.richText && typeof wp.richText.toHTMLString==='function' && value){
            try { return String(wp.richText.toHTMLString({value:value})||''); } catch(e2){}
            try { return String(wp.richText.toHTMLString(value)||''); } catch(e3){}
        }
        if(value && typeof value.originalHTML==='string'){ return value.originalHTML; }
        return '';
    }

    function editableTextKeys(block){
        var attrs=block.attributes||{}, keys=[], seen={};
        var preferred=['content','text','description','value','editor'];
        preferred.forEach(function(key){
            if(attributeHtml(attrs[key])){ seen[key]=true; keys.push(key); }
        });
        try {
            if(window.wp && wp.blocks && typeof wp.blocks.getBlockType==='function'){
                var type=wp.blocks.getBlockType(block.name), schema=type && type.attributes ? type.attributes : {};
                Object.keys(schema).forEach(function(key){
                    var def=schema[key]||{};
                    if(seen[key] || !attributeHtml(attrs[key])){ return; }
                    if(/^(url|href|src|alt|caption|title|heading|label|placeholder|id|className)$/i.test(key)){ return; }
                    if(def.role==='content' || def.source==='html' || def.source==='text'){
                        seen[key]=true; keys.push(key);
                    }
                });
            }
        } catch(e){}
        return keys;
    }

    function exactAnchorInHtml(html,needle){
        if(!html || !needle){ return ''; }
        var wrap=inspectionRootFromHtml(html);
        if(!wrap){ return ''; }
        var walker=document.createTreeWalker(wrap,NodeFilter.SHOW_TEXT,null), node;
        var lowerNeedle=String(needle).toLocaleLowerCase();
        while((node=walker.nextNode())){
            if(node.parentElement && node.parentElement.closest('a,script,style,code,pre,h1,h2,h3,h4,h5,h6,figure,figcaption,button')){ continue; }
            var text=String(node.nodeValue||''), index=text.toLocaleLowerCase().indexOf(lowerNeedle);
            if(index!==-1){ return text.slice(index,index+String(needle).length); }
        }
        return '';
    }

    function findAnchorInBlocks(blocks,needle){
        for(var i=0;i<blocks.length;i++){
            var block=blocks[i];
            if(isExcludedBlock(block.name)){ continue; }
            var keys=editableTextKeys(block);
            for(var k=0;k<keys.length;k++){
                var exact=exactAnchorInHtml(attributeHtml(block.attributes[keys[k]]),needle);
                if(exact){ return exact; }
            }
            if(block.innerBlocks && block.innerBlocks.length){
                var nested=findAnchorInBlocks(block.innerBlocks,needle);
                if(nested){ return nested; }
            }
        }
        return '';
    }

    function textFromSafeHtml(html){
        var wrap=inspectionRootFromHtml(html);
        if(!wrap){ return ''; }
        var unsafe=wrap.querySelectorAll('h1,h2,h3,h4,h5,h6,figure,figcaption,picture,img,svg,canvas,button,nav,code,pre,script,style,form,a');
        for(var i=0;i<unsafe.length;i++){ unsafe[i].remove(); }
        return String(wrap.textContent||'').replace(/\s+/g,' ').trim();
    }

    function wordTextFromContextHtml(html){
        var wrap=inspectionRootFromHtml(html);
        if(!wrap){ return ''; }
        var unsafe=wrap.querySelectorAll('h1,h2,h3,h4,h5,h6,figure,figcaption,picture,img,svg,canvas,button,nav,code,pre,script,style,form');
        for(var i=0;i<unsafe.length;i++){ unsafe[i].remove(); }
        return String(wrap.textContent||'').replace(/\s+/g,' ').trim();
    }

    function collectEditorSnapshot(){
        var snapshot={bodyText:'',bodyWordText:'',existingUrls:[],contextualInternalLinks:0,contextualExternalLinks:0,visibleInternalLinks:0,visibleExternalLinks:0,blockCount:0,segments:[]};
        var texts=[],wordTexts=[],urls=[],seenUrls={};

        function inspectHtml(html,clientId,attribute){
            html=String(html||'');
            if(!html){ return; }
            var wrap=inspectionRootFromHtml(html);
            if(!wrap){ return; }
            var links=wrap.querySelectorAll('a[href]');
            for(var i=0;i<links.length;i++){
                var normalized=normalizeUrl(links[i].getAttribute('href'));
                var kind=linkKind(links[i].getAttribute('href'));
                if(kind==='internal'){ snapshot.contextualInternalLinks++; }
                else if(kind==='external'){ snapshot.contextualExternalLinks++; }
                if(normalized && !seenUrls[normalized]){ seenUrls[normalized]=true; urls.push(normalized); }
            }
            var wordText=wordTextFromContextHtml(html);
            if(wordText){ wordTexts.push(wordText); }
            var text=textFromSafeHtml(html);
            if(text){
                texts.push(text);
                snapshot.segments.push({clientId:String(clientId||''),attribute:String(attribute||''),text:text});
            }
        }

        function walk(blocks){
            (blocks||[]).forEach(function(block){
                if(!block){ return; }
                snapshot.blockCount++;
                if(!isExcludedBlock(block.name)){
                    var attrs=block.attributes||{},keys=editableTextKeys(block);
                    keys.forEach(function(key){ inspectHtml(attributeHtml(attrs[key]),block.clientId,key); });
                    // Some third-party blocks keep editable markup in originalContent.
                    if(!keys.length && typeof block.originalContent==='string'){
                        inspectHtml(block.originalContent,block.clientId,'originalContent');
                    }
                }
                if(block.innerBlocks && block.innerBlocks.length){ walk(block.innerBlocks); }
            });
        }

        var blocks=getEditorBlocks();
        if(blocks.length){ walk(blocks); }

        // Fallback to the current unsaved serialized post content. This is important
        // for iframe editors, classic blocks, patterns and third-party block stores.
        if(!texts.length){
            var edited=getEditedPostContent();
            if(edited){
                if(window.wp && wp.blocks && typeof wp.blocks.parse==='function'){
                    try { walk(wp.blocks.parse(edited)||[]); } catch(e){}
                }
                if(!texts.length){ inspectHtml(edited,'','content'); }
            }
        }

        // Last-resort visual editor canvas extraction. Used only for analysis;
        // insertion still requires an editable block or serialized post content.
        if(!texts.length){
            try {
                var docs=[document];
                var iframe=document.querySelector('iframe[name="editor-canvas"],iframe.editor-canvas__iframe');
                if(iframe && iframe.contentDocument){ docs.push(iframe.contentDocument); }
                docs.forEach(function(doc){
                    var canvas=doc.querySelector('.block-editor-writing-flow,.editor-styles-wrapper,[data-type="core/post-content"]');
                    if(canvas){ inspectHtml(canvas.innerHTML,'','canvas'); }
                });
            } catch(e3){}
        }

        snapshot.bodyText=texts.join('\n').replace(/\s+/g,' ').trim().slice(0,200000);
        snapshot.bodyWordText=wordTexts.join('\n').replace(/\s+/g,' ').trim().slice(0,200000);
        snapshot.existingUrls=urls.slice(0,1000);
        snapshot.segments=snapshot.segments.slice(0,5000);
        snapshot.visibleInternalLinks=snapshot.contextualInternalLinks;
        snapshot.visibleExternalLinks=snapshot.contextualExternalLinks;
        try {
            var docs=[document],iframe=document.querySelector('iframe[name="editor-canvas"],iframe.editor-canvas__iframe');
            if(iframe && iframe.contentDocument){ docs.push(iframe.contentDocument); }
            docs.forEach(function(doc){
                var canvas=doc.querySelector('[data-type="core/post-content"],.block-editor-writing-flow,.editor-styles-wrapper');
                if(!canvas){ return; }
                var internalCount=0,externalCount=0,renderedLinks=canvas.querySelectorAll('a[href]');
                for(var i=0;i<renderedLinks.length;i++){
                    var kind=linkKind(renderedLinks[i].getAttribute('href'));
                    if(kind==='internal'){ internalCount++; }
                    else if(kind==='external'){ externalCount++; }
                }
                snapshot.visibleInternalLinks=Math.max(snapshot.visibleInternalLinks,internalCount);
                snapshot.visibleExternalLinks=Math.max(snapshot.visibleExternalLinks,externalCount);
            });
        } catch(e4){}
        return snapshot;
    }

    function safeAnchorsForCurrentEditor(anchors){
        anchors=(anchors||[]).filter(Boolean);
        if(!getEditorBlocks().length){ return anchors; }
        var blocks=getEditorBlocks(), out=[], seen={};
        anchors.forEach(function(anchor){
            var exact=findAnchorInBlocks(blocks,anchor);
            var key=String(exact||'').toLocaleLowerCase();
            if(exact && !seen[key]){ seen[key]=true; out.push(exact); }
        });
        return out;
    }

    function findAndInsertInBlocks(blocks,anchor,url){
        for(var i=0;i<blocks.length;i++){
            var block=blocks[i];
            if(isExcludedBlock(block.name)){ continue; }
            var attrs=block.attributes||{}, keys=editableTextKeys(block);
            for(var k=0;k<keys.length;k++){
                var key=keys[k], original=attributeHtml(attrs[key]), changed=replaceTextNodeOnce(original,anchor,url);
                if(changed!==null && changed!==original){
                    var update={}; update[key]=changed;
                    wp.data.dispatch('core/block-editor').updateBlockAttributes(block.clientId,update);
                    return {clientId:block.clientId,key:key,original:original};
                }
            }
            if(block.innerBlocks && block.innerBlocks.length){
                var nested=findAndInsertInBlocks(block.innerBlocks,anchor,url);
                if(nested){ return nested; }
            }
        }
        return null;
    }

    function blockContainsLink(location,url,anchor){
        if(!location || !(window.wp && wp.data)){ return false; }
        var block=wp.data.select('core/block-editor').getBlock(location.clientId);
        if(!block || !block.attributes){ return false; }
        var html=attributeHtml(block.attributes[location.key]),wrap=inspectionRootFromHtml(html);
        if(!wrap){ return false; }
        var links=wrap.querySelectorAll('a[href]');
        for(var i=0;i<links.length;i++){
            if(normalizeUrl(links[i].getAttribute('href'))===normalizeUrl(url) && String(links[i].textContent||'').trim().toLocaleLowerCase()===String(anchor).trim().toLocaleLowerCase()){
                return true;
            }
        }
        return false;
    }

    function verifyGutenbergInsert(location,url,anchor){
        return new Promise(function(resolve){
            var tries=0;
            function check(){
                tries++;
                if(blockContainsLink(location,url,anchor)){ resolve(true); return; }
                if(tries<10){ window.setTimeout(check,80); return; }
                try {
                    var rollback={}; rollback[location.key]=location.original;
                    wp.data.dispatch('core/block-editor').updateBlockAttributes(location.clientId,rollback);
                } catch(e){}
                resolve(false);
            }
            window.setTimeout(check,40);
        });
    }

    function findAndInsertInParsedBlocks(blocks,anchor,url){
        for(var i=0;i<(blocks||[]).length;i++){
            var block=blocks[i];
            if(!block){ continue; }
            if(!isExcludedBlock(block.name)){
                var attrs=block.attributes||{},keys=editableTextKeys(block);
                for(var k=0;k<keys.length;k++){
                    var key=keys[k],original=attributeHtml(attrs[key]),changed=replaceTextNodeOnce(original,anchor,url);
                    if(changed!==null && changed!==original){
                        block.attributes[key]=changed;
                        return true;
                    }
                }
            }
            if(block.innerBlocks && block.innerBlocks.length && findAndInsertInParsedBlocks(block.innerBlocks,anchor,url)){ return true; }
        }
        return false;
    }

    function insertViaSerializedContent(url,anchor){
        if(!(window.wp && wp.blocks && typeof wp.blocks.parse==='function' && typeof wp.blocks.serialize==='function' && window.wp.data)){ return null; }
        var original=getEditedPostContent();
        if(!original){ return null; }
        var parsed;
        try { parsed=wp.blocks.parse(original)||[]; } catch(e){ return null; }
        if(!findAndInsertInParsedBlocks(parsed,anchor,url)){ return null; }
        var changed='';
        try { changed=wp.blocks.serialize(parsed); } catch(e2){ return null; }
        if(!changed || changed===original){ return null; }
        var editorDispatch=wp.data.dispatch('core/editor');
        if(!editorDispatch || typeof editorDispatch.editPost!=='function'){ return null; }
        editorDispatch.editPost({content:changed});
        return {serialized:true,original:original,changed:changed};
    }

    function serializedContentContainsLink(url,anchor){
        var html=String(getEditedPostContent()||'').replace(/<!--\/?wp:[\s\S]*?-->/g,'');
        // Parse serialized content in an inert document before inspection.
        var wrap=inspectionRootFromHtml(html);
        if(!wrap){ return false; }
        var links=wrap.querySelectorAll('a[href]');
        for(var i=0;i<links.length;i++){
            if(normalizeUrl(links[i].getAttribute('href'))===normalizeUrl(url) && String(links[i].textContent||'').trim().toLocaleLowerCase()===String(anchor).trim().toLocaleLowerCase()){
                return true;
            }
        }
        return false;
    }

    function verifySerializedInsert(location,url,anchor){
        return new Promise(function(resolve){
            var tries=0;
            function check(){
                tries++;
                if(serializedContentContainsLink(url,anchor)){ resolve(true); return; }
                if(tries<10){ window.setTimeout(check,80); return; }
                try {
                    var editorDispatch=wp.data.dispatch('core/editor');
                    if(editorDispatch && typeof editorDispatch.editPost==='function'){
                        editorDispatch.editPost({content:location.original});
                    }
                } catch(e){}
                resolve(false);
            }
            window.setTimeout(check,50);
        });
    }

    function insertAtKnownLocation(item,anchor){
        var locations=item && item.anchor_locations ? item.anchor_locations : {};
        var known=locations[String(anchor||'').toLocaleLowerCase()];
        if(!known || !known.clientId || !known.attribute || !(window.wp && wp.data)){ return null; }
        var store=wp.data.select('core/block-editor'), dispatch=wp.data.dispatch('core/block-editor');
        if(!store || !dispatch){ return null; }
        var block=store.getBlock(known.clientId);
        if(!block || !block.attributes || !attributeHtml(block.attributes[known.attribute])){ return null; }
        var original=attributeHtml(block.attributes[known.attribute]);
        var changed=replaceTextNodeOnce(original,anchor,item.url);
        if(changed===null || changed===original){ return null; }
        var update={}; update[known.attribute]=changed;
        dispatch.updateBlockAttributes(known.clientId,update);
        return {clientId:known.clientId,key:known.attribute,original:original};
    }
    function insertGutenberg(url,anchor){
        var blocks=getEditorBlocks();
        if(editorStoreAvailable() && blocks.length){
            var direct=findAndInsertInBlocks(blocks,anchor,url);
            if(direct){ return direct; }
        }
        return insertViaSerializedContent(url,anchor);
    }

    function insertClassic(url,anchor){
        var html=createLinkHtml(url,anchor);
        if(window.tinyMCE && tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden()){
            var node=tinyMCE.activeEditor.selection.getNode();
            if(node && tinyMCE.activeEditor.dom.getParent(node,'h1,h2,h3,h4,h5,h6')){ return false; }
            tinyMCE.activeEditor.execCommand('mceInsertContent',false,html); return true;
        }
        var $content=$('#content');
        if($content.length){
            var el=$content[0], start=el.selectionStart||0, end=el.selectionEnd||0;
            el.value=el.value.substring(0,start)+html+el.value.substring(end);
            el.selectionStart=el.selectionEnd=start+html.length; $content.trigger('input change'); return true;
        }
        return false;
    }

    function copyText(text){
        if(navigator.clipboard && navigator.clipboard.writeText){ return navigator.clipboard.writeText(text); }
        var ta=document.createElement('textarea'); ta.value=text; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); return Promise.resolve();
    }

    function feedback(target,decision){
        $.post(ILSM_EDITOR.ajaxUrl,{action:'ilsm_record_suggestion',nonce:ILSM_EDITOR.nonce,source_post_id:currentPostId(),target_post_id:target,decision:decision});
    }

    function formatN(template,values){
        var out=String(template||'');
        (values||[]).forEach(function(value,index){ out=out.replace(new RegExp('%'+(index+1)+'\\$d','g'),String(value)); });
        return out;
    }

    function refreshBudgetPanel($panel,metrics){
        metrics=metrics||{};
        var links=parseInt(metrics.contextual_links,10)||0,external=parseInt(metrics.contextual_external_links,10)||0,max=parseInt(metrics.recommended_max,10)||2;
        var visibleInternal=Math.max(links,parseInt(metrics.visible_internal_links,10)||links),visibleExternal=Math.max(external,parseInt(metrics.visible_external_links,10)||external);
        var visible=visibleInternal+visibleExternal,embedded=Math.max(0,visible-links-external);
        metrics.remaining_budget=Math.max(0,max-visible);
        metrics.state=visible>max?'crowded':(visible===max?'exceeded':(metrics.remaining_budget<=1?'near':'healthy'));
        metrics.visible_internal_links=visibleInternal; metrics.visible_external_links=visibleExternal; metrics.visible_total=visible; metrics.embedded_links=embedded;
        var summary=formatN(ILSM_EDITOR.strings.linkBudgetSummary,[links,external,embedded,visible,parseInt(metrics.word_count,10)||0,max]);
        var advice=metrics.state==='exceeded'?ILSM_EDITOR.strings.linkBudgetExceeded:(metrics.state==='crowded'?ILSM_EDITOR.strings.linkBudgetCrowded:(metrics.state==='near'?ILSM_EDITOR.strings.linkBudgetNear:ILSM_EDITOR.strings.linkBudgetHealthy));
        $panel.data('link-metrics',metrics);
        $panel.find('.ilsm-link-budget').removeClass('is-healthy is-near is-exceeded is-crowded').addClass('is-'+metrics.state).find('.ilsm-link-budget-summary').text(summary);
        $panel.find('.ilsm-link-budget-advice').text(advice);
        $panel.find('.ilsm-link-budget-title span').text(visible+' / '+max);
    }

    function budgetMarkup(){
        return '<div class="ilsm-link-budget" role="status"><div class="ilsm-link-budget-title"><i class="fa fa-balance-scale" aria-hidden="true"></i><strong>'+esc(ILSM_EDITOR.strings.linkBudgetTitle)+'</strong><span></span></div><p class="ilsm-link-budget-summary"></p><p class="ilsm-link-budget-advice"></p></div>';
    }

    function render(items,metrics){
        var $results=$('.ilsm-assistant-results').empty();
        var seenPosts={},seenUrls={},unique=[];
        (items||[]).forEach(function(item){
            var pid=parseInt(item.post_id,10)||0, url=normalizeUrl(item.url);
            if(!pid || !url || seenPosts[pid] || seenUrls[url]){ return; }
            seenPosts[pid]=true; seenUrls[url]=true; unique.push(item);
        });
        unique=unique.map(function(item){
            var copy=$.extend(true,{},item);
            copy.anchors=(copy.anchors||[]).filter(function(anchor){
                if(!isValidNaturalAnchor(anchor)){ return false; }
                var locations=copy.anchor_locations||{};
                return !!locations[String(anchor||'').toLocaleLowerCase()] || !!findAnchorInBlocks(getEditorBlocks(),anchor);
            });
            return copy;
        }).filter(function(item){ return item.anchors && item.anchors.length; }).slice(0,8);
        if(!unique.length){
            var $empty=$('<div>'+budgetMarkup()+'<div class="ilsm-assistant-empty"><span class="fa fa-info-circle"></span>'+esc(ILSM_EDITOR.strings.noBodyOpportunities)+'</div></div>');
            $results.append($empty); refreshBudgetPanel($empty,metrics||{});
            return;
        }

        var $panel=$('<section class="ilsm-opportunity-panel">'+
            '<div class="ilsm-results-heading"><span><i class="fa fa-magic" aria-hidden="true"></i> Best link opportunities</span><small>'+unique.length+' strong matches</small></div>'+
            budgetMarkup()+
            '<label class="ilsm-field-label" for="ilsm-target-select">Suggested page</label><select id="ilsm-target-select" class="ilsm-target-select"></select>'+
            '<div class="ilsm-selected-summary"><div class="ilsm-selected-title"></div><div class="ilsm-score-pill"></div><p class="ilsm-selected-reason"></p><section class="ilsm-intent-journey" aria-label="'+esc(ILSM_EDITOR.strings.intentJourney)+'"><div class="ilsm-intent-journey__head"><i class="fa fa-random" aria-hidden="true"></i><strong>'+esc(ILSM_EDITOR.strings.intentJourney)+'</strong></div><div class="ilsm-intent-route"><div class="ilsm-intent-stop is-source"><small>'+esc(ILSM_EDITOR.strings.intentCurrentPost)+'</small><strong></strong><span></span></div><i class="fa fa-long-arrow-right ilsm-intent-arrow" aria-hidden="true"></i><div class="ilsm-intent-stop is-target"><small>'+esc(ILSM_EDITOR.strings.intentSuggestedPage)+'</small><strong></strong><span></span></div></div><p class="ilsm-intent-explanation"></p></section><div class="ilsm-search-evidence" hidden></div><div class="ilsm-shared-terms"></div></div>'+
            '<label class="ilsm-field-label" for="ilsm-anchor-select">Natural anchor in body text</label><select id="ilsm-anchor-select" class="ilsm-anchor-select"></select>'+
            '<div class="ilsm-safe-note"><i class="fa fa-shield" aria-hidden="true"></i> Inserts only into body text. Headings, images, captions, galleries and existing links are excluded.</div>'+
            '<div class="ilsm-suggestion-actions"><button type="button" class="button button-primary ilsm-insert"><span class="fa fa-link"></span> Insert link</button><button type="button" class="button ilsm-view-location" hidden><span class="fa fa-eye"></span><span class="screen-reader-text">View inserted link</span></button><button type="button" class="button-link-delete ilsm-ignore">Ignore</button></div>'+
            '</section>');
        unique.forEach(function(item,index){
            $('<option>').val(index).text(compactLabel(item.title,34)+' · '+parseInt(item.score,10)+'%').attr('title',item.title+' · '+parseInt(item.score,10)+'%').appendTo($panel.find('.ilsm-target-select'));
        });
        $panel.data('items',unique);
        refreshBudgetPanel($panel,metrics||{});
        $results.append($panel);
        updateSelectedOpportunity($panel,0);
    }

    function updateSelectedOpportunity($panel,index){
        var items=$panel.data('items')||[], item=items[parseInt(index,10)||0];
        if(!item){ return; }
        var anchors=(item.anchors||[]).filter(Boolean);
        var $anchor=$panel.find('.ilsm-anchor-select').empty();
        anchors.forEach(function(a){ $('<option>').val(a).text(a).appendTo($anchor); });
        $panel.find('.ilsm-insert').prop('disabled',!anchors.length);
        if(!anchors.length){ $('<option>').val('').text(ILSM_EDITOR.strings.noSafeAnchor).appendTo($anchor); }
        $panel.find('.ilsm-selected-title').text(item.title);
        $panel.find('.ilsm-score-pill').text(parseInt(item.score,10)+'% relevant');
        $panel.find('.ilsm-selected-reason').text(item.reason||'');
        var sourceIntent=item.source_intent||{},targetIntent=item.target_intent||{};
        function intentClass(value){ value=String(value||'informational').toLowerCase(); return /^(informational|commercial|transactional|navigational)$/.test(value)?'is-'+value:'is-informational'; }
        function confidence(value){ return String(ILSM_EDITOR.strings.intentConfidence||'%d%% confidence').replace('%d',parseInt(value,10)||0); }
        var $journey=$panel.find('.ilsm-intent-journey'),sourceKey=String(sourceIntent.primary||'informational'),targetKey=String(targetIntent.primary||'informational');
        $journey.find('.is-source').removeClass('is-informational is-commercial is-transactional is-navigational').addClass(intentClass(sourceKey)).find('strong').text(sourceIntent.label||'Informational');
        $journey.find('.is-source span').text(confidence(sourceIntent.confidence));
        $journey.find('.is-target').removeClass('is-informational is-commercial is-transactional is-navigational').addClass(intentClass(targetKey)).find('strong').text(targetIntent.label||'Informational');
        $journey.find('.is-target span').text(confidence(targetIntent.confidence));
        var journeyMap={
            'informational>commercial':ILSM_EDITOR.strings.intentJourneyInformationalCommercial,
            'informational>transactional':ILSM_EDITOR.strings.intentJourneyInformationalTransactional,
            'commercial>transactional':ILSM_EDITOR.strings.intentJourneyCommercialTransactional,
            'informational>informational':ILSM_EDITOR.strings.intentJourneyInformationalInformational,
            'transactional>informational':ILSM_EDITOR.strings.intentJourneyTransactionalInformational
        };
        $journey.find('.ilsm-intent-explanation').text(journeyMap[sourceKey+'>'+targetKey]||ILSM_EDITOR.strings.intentJourneyNeutral);
        var search=item.search_console||{},$search=$panel.find('.ilsm-search-evidence');
        if(parseInt(search.impressions,10)>0){
            var evidence=String(ILSM_EDITOR.strings.searchConsoleEvidence||'').replace('%1$s',(parseInt(search.impressions,10)||0).toLocaleString()).replace('%2$s',(parseFloat(search.position)||0).toFixed(1));
            $search.html('<i class="fa fa-line-chart" aria-hidden="true"></i>'+esc(evidence)).prop('hidden',false);
        } else { $search.empty().prop('hidden',true); }
        $panel.find('.ilsm-shared-terms').html((item.shared_terms||[]).slice(0,5).map(function(t){return '<span>'+esc(t)+'</span>';}).join(''));
        $panel.data('item',item).removeClass('is-accepted').removeData('inserted-block inserted-url inserted-anchor');
        $panel.find('.ilsm-view-location').prop('hidden',true);
    }

    $(document).on('click','.ilsm-assistant-analyse',function(){
        var $btn=$(this),$status=$('.ilsm-assistant-status');
        $btn.prop('disabled',true); $status.html('<span class="spinner is-active"></span>'+esc(ILSM_EDITOR.strings.loading));
        var snapshot=collectEditorSnapshot();
        if(!snapshot.bodyText){
            $status.text(ILSM_EDITOR.strings.noEditorText); $btn.prop('disabled',false); return;
        }
        $.post(ILSM_EDITOR.ajaxUrl,{action:'ilsm_local_suggestions',nonce:ILSM_EDITOR.nonce,post_id:currentPostId(),live_body_text:snapshot.bodyText,live_body_word_text:snapshot.bodyWordText,live_contextual_links:snapshot.contextualInternalLinks,live_contextual_external_links:snapshot.contextualExternalLinks,live_visible_internal_links:snapshot.visibleInternalLinks,live_visible_external_links:snapshot.visibleExternalLinks,live_existing_urls:JSON.stringify(snapshot.existingUrls),live_segments:JSON.stringify(snapshot.segments)})
            .done(function(r){ if(!r.success){ throw new Error(r.data && r.data.message ? r.data.message : ILSM_EDITOR.strings.error); } render(r.data.suggestions||[],r.data.link_metrics||{}); $status.text(r.data.privacy||''); })
            .fail(function(xhr){ var m=xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message; $status.text(m||ILSM_EDITOR.strings.error); })
            .always(function(){ $btn.prop('disabled',false); });
    });

    $(document).on('change','.ilsm-target-select',function(){ updateSelectedOpportunity($(this).closest('.ilsm-opportunity-panel'),this.value); });

    $(document).on('click','.ilsm-insert',function(){
        var $card=$(this).closest('.ilsm-opportunity-panel'), item=$card.data('item'), anchor=String($card.find('.ilsm-anchor-select').val()||'').trim();
        if(!item || !anchor){ $('.ilsm-assistant-status').text(ILSM_EDITOR.strings.noSafeLocation); return; }
        if(!isValidNaturalAnchor(anchor)){ $('.ilsm-assistant-status').text(ILSM_EDITOR.strings.invalidNaturalAnchor); return; }
        var metrics=$card.data('link-metrics')||{};
        if(metrics.state==='exceeded' && !window.confirm(formatN(ILSM_EDITOR.strings.linkBudgetConfirm,[parseInt(metrics.contextual_links,10)||0,parseInt(metrics.word_count,10)||0,parseInt(metrics.recommended_max,10)||2]))){ return; }
        if(metrics.state==='crowded' && !window.confirm(formatN(ILSM_EDITOR.strings.linkCrowdedConfirm,[parseInt(metrics.visible_total,10)||0,parseInt(metrics.embedded_links,10)||0]))){ return; }
        // The analysis response already contains the exact live Gutenberg block
        // and attribute for this anchor. Use it first. Do not reject it by running
        // the older whole-document search again, because third-party RichText and
        // iframe editors can serialize differently from their live attributes.
        var location=insertAtKnownLocation(item,anchor);
        if(!location){
            var currentSafe=safeAnchorsForCurrentEditor([anchor]);
            anchor=currentSafe.length ? currentSafe[0] : anchor;
            location=insertGutenberg(item.url,anchor);
        }
        if(location){
            var $button=$(this).prop('disabled',true);
            var verifier=location.serialized ? verifySerializedInsert(location,item.url,anchor) : verifyGutenbergInsert(location,item.url,anchor);
            verifier.then(function(ok){
                $button.prop('disabled',false);
                if(!ok){ $('.ilsm-assistant-status').text(ILSM_EDITOR.strings.insertFailed); return; }
                feedback(item.post_id,'accepted');
                metrics.contextual_links=(parseInt(metrics.contextual_links,10)||0)+1;
                metrics.visible_internal_links=(parseInt(metrics.visible_internal_links,10)||0)+1;
                refreshBudgetPanel($card,metrics);
                $card.addClass('is-accepted').data('inserted-block',location.clientId||'').data('inserted-url',item.url).data('inserted-anchor',anchor);
                $card.find('.ilsm-view-location').prop('hidden',false).attr('aria-label',ILSM_EDITOR.strings.viewInserted);
                $('.ilsm-assistant-status').text(ILSM_EDITOR.strings.inserted);
                if(location.clientId){ scrollToBlock(location.clientId,item.url,anchor); }
            });
            return;
        }
        if(getEditorBlocks().length || getEditedPostContent()){
            $('.ilsm-assistant-status').text(ILSM_EDITOR.strings.noSafeLocation);
            return;
        }
        if(insertClassic(item.url,anchor)){
            feedback(item.post_id,'accepted'); $card.addClass('is-accepted'); $('.ilsm-assistant-status').text(ILSM_EDITOR.strings.inserted); return;
        }
        copyText(createLinkHtml(item.url,anchor)); feedback(item.post_id,'accepted'); $('.ilsm-assistant-status').text(ILSM_EDITOR.strings.copied);
    });

    function scrollToVisibleLink(url,anchor){
        var docs=[document];
        try {
            var iframe=document.querySelector('iframe[name="editor-canvas"],iframe.editor-canvas__iframe');
            if(iframe && iframe.contentDocument){ docs.push(iframe.contentDocument); }
        } catch(e){}
        for(var d=0;d<docs.length;d++){
            var links=docs[d].querySelectorAll('a[href]');
            for(var i=0;i<links.length;i++){
                if(normalizeUrl(links[i].getAttribute('href'))===normalizeUrl(url) && String(links[i].textContent||'').trim().toLocaleLowerCase()===String(anchor||'').trim().toLocaleLowerCase()){
                    links[i].scrollIntoView({behavior:'smooth',block:'center'});
                    links[i].classList.add('ilsm-inserted-link-flash');
                    (function(link){ window.setTimeout(function(){ link.classList.remove('ilsm-inserted-link-flash'); },2200); })(links[i]);
                    return true;
                }
            }
        }
        return false;
    }

    $(document).on('click','.ilsm-view-location',function(){
        var $card=$(this).closest('.ilsm-opportunity-panel'),blockId=$card.data('inserted-block');
        if(blockId){ scrollToBlock(blockId,$card.data('inserted-url'),$card.data('inserted-anchor')); }
        else { scrollToVisibleLink($card.data('inserted-url'),$card.data('inserted-anchor')); }
    });

    $(document).on('click','.ilsm-ignore',function(){ var $c=$(this).closest('.ilsm-opportunity-panel'), item=$c.data('item'); if(item){ feedback(item.post_id,'ignored'); } $c.slideUp(160); });
})(jQuery);
