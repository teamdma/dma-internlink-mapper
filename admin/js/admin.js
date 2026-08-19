function ilsmAdminT(key,fallback){return (window.ILSM&&ILSM.strings&&ILSM.strings[key])?ILSM.strings[key]:fallback;}
function ilsmT(key,fallback){return ilsmAdminT(key,fallback);}
function ilsmFmt(key,fallback,value){return ilsmT(key,fallback).replace('%s',String(value));}
function ilsmFmtN(key,fallback,values){var out=ilsmT(key,fallback);(values||[]).forEach(function(value,index){out=out.replace('%'+(index+1)+'$s',String(value));});return out;}
function esc(value){var node=document.createElement('div');node.textContent=value==null?'':String(value);return node.innerHTML;}

(function(){
'use strict';
function initILSMGlobalTheme(){
  var wrap=document.querySelector('.ilsm-wrap');
  if(!wrap)return;
  var toggle=wrap.querySelector('.ilsm-global-theme-toggle');
  var key='ilsm_global_admin_theme';
  var configured=(window.ILSM&&ILSM.defaultTheme)?ILSM.defaultTheme:'dark';
  var fallback=configured==='system'?(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):configured;
  var theme=fallback;
  try{theme=window.localStorage.getItem(key)||fallback;}catch(e){theme=fallback;}

  function apply(next,save){
    theme=next==='light'?'light':'dark';
    wrap.setAttribute('data-ilsm-theme',theme);
    document.body.classList.toggle('ilsm-admin-theme-dark',theme==='dark');
    document.body.classList.toggle('ilsm-admin-theme-light',theme==='light');

    var kg=wrap.querySelector('[data-knowledge-graph]');
    if(kg){
      kg.setAttribute('data-theme',theme);
      try{window.localStorage.setItem('ilsm_knowledge_graph_theme',theme);}catch(e){}
      // Existing graph renderer reads its local theme state. Trigger its own toggle
      // only when needed; otherwise the CSS still changes immediately.
      var kgToggle=kg.querySelector('.ilsm-kg-theme-toggle');
      if(kgToggle){kgToggle.hidden=true;}
    }

    if(toggle){
      var icon=toggle.querySelector('i'),label=toggle.querySelector('span'),dark=theme==='dark';
      toggle.setAttribute('aria-pressed',dark?'true':'false');
      toggle.setAttribute('title',dark?ilsmAdminT('switchLight','Switch DMA InternLink Mapper to light mode'):ilsmAdminT('switchDark','Switch DMA InternLink Mapper to dark mode'));
      if(icon){icon.className='fa '+(dark?'fa-sun-o':'fa-moon-o');}
      if(label){label.textContent=dark?ilsmAdminT('light','Light'):ilsmAdminT('dark','Dark');}
    }
    if(save){try{window.localStorage.setItem(key,theme);}catch(e){}}
    document.dispatchEvent(new CustomEvent('ilsmThemeChanged',{detail:{theme:theme}}));
  }

  apply(theme,false);
  if(toggle){
    toggle.addEventListener('click',function(){
      apply(theme==='dark'?'light':'dark',true);
    });
  }
}
if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',initILSMGlobalTheme);}
else{initILSMGlobalTheme();}
}());

jQuery(function($){
    function ilsmT(key, fallback){ return ilsmAdminT(key, fallback); }
    function ilsmFmt(key, fallback, value){ return ilsmT(key, fallback).replace('%s', String(value)); }
    function ilsmFmtN(key, fallback, values){ var out=ilsmT(key,fallback); (values||[]).forEach(function(value,index){ out=out.replace('%'+(index+1)+'$s',String(value)); }); return out; }
    'use strict';

    let ilsmDialogReturnFocus=null;
    function focusableElements(container){
        return Array.from(container.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')).filter(function(el){return !el.hidden&&el.offsetParent!==null;});
    }
    function trapDialogFocus(event){
        const modal=document.getElementById('ilsm-insert-modal');
        if(!modal||modal.hidden)return;
        if(event.key==='Escape'){event.preventDefault();modal.hidden=true;if(ilsmDialogReturnFocus)ilsmDialogReturnFocus.focus();return;}
        if(event.key!=='Tab')return;
        const items=focusableElements(modal);if(!items.length)return;
        const first=items[0],last=items[items.length-1];
        if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
        else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
    }
    document.addEventListener('keydown',trapDialogFocus);
    let scanId=ILSM.currentScan&&ILSM.currentScan.id?parseInt(ILSM.currentScan.id,10):0, token='', active=false, scanRetry=0;
    let scanStatusTimer=null,scanStatusEpoch=0;
    let scanQuoteTimer=null,scanQuoteIndex=0;
    const scanQuotes=[
        'Orphan pages can be excellent content that the rest of the site simply forgot to introduce.',
        'A useful internal link answers the reader’s next question before they need to search for it.',
        'Strong site architecture makes important pages easy to discover without forcing every page to link everywhere.',
        'Descriptive anchor text helps people and search engines understand what waits on the other side.',
        'A smaller number of relevant links is usually more helpful than a page crowded with unrelated choices.',
        'Broken internal links interrupt both the visitor’s journey and the site’s information structure.',
        'Content becomes easier to navigate when related pages acknowledge one another naturally.',
        'The best link opportunity improves the sentence for a human reader before it improves any report.',
        'A fresh scan is a snapshot of the site now, not a promise about what search engines will rank later.',
        'Good internal linking distributes context, not just clicks.'
    ];
    function renderScanQuote(){
        const quote=scanQuotes[scanQuoteIndex%scanQuotes.length];
        $('#ilsm-scan-quote-text').text('“'+quote+'”');
        $('#ilsm-scan-quote-count').text((scanQuoteIndex%scanQuotes.length+1)+' / '+scanQuotes.length);
        scanQuoteIndex++;
    }
    function startScanQuotes(){
        const $card=$('#ilsm-scan-quotes');if(!$card.length)return;
        $card.prop('hidden',false).removeClass('is-paused');
        if(!scanQuoteTimer){renderScanQuote();scanQuoteTimer=window.setInterval(renderScanQuote,9000);}
    }
    function pauseScanQuotes(){
        if(scanQuoteTimer){window.clearInterval(scanQuoteTimer);scanQuoteTimer=null;}
        $('#ilsm-scan-quotes').removeAttr('hidden').addClass('is-paused');
    }
    function stopScanQuotes(){
        if(scanQuoteTimer){window.clearInterval(scanQuoteTimer);scanQuoteTimer=null;}
        $('#ilsm-scan-quotes').prop('hidden',true).removeClass('is-paused');
    }
    const post=(action,data)=>$.post(ILSM.ajax,Object.assign({action:action,nonce:ILSM.nonce},data||{}));
    const notify=(message,type)=>{
        const noticeType=['success','error','warning','info'].includes(type)?type:'success';
        const box=$('<div class="notice is-dismissible ilsm-notice"><p></p></div>')
            .addClass('notice-'+noticeType)
            .attr('role',noticeType==='error'?'alert':'status')
            .attr('aria-live',noticeType==='error'?'assertive':'polite');
        box.find('p').text(message);
        $('.ilsm-wrap').prepend(box);
        setTimeout(()=>box.fadeOut(250,()=>box.remove()),6500);
    };
    const domainFlashKey='ilsm_external_domain_action_flash';
    function showDomainFeedback(message,type,focus){
        const feedback=$('#ilsm-domain-action-feedback');
        if(!feedback.length){notify(message,type);return;}
        const feedbackType=['success','error','warning','info'].includes(type)?type:'success';
        feedback.removeClass('is-success is-error is-warning is-info').addClass('is-'+feedbackType);
        feedback.attr('role',feedbackType==='error'?'alert':'status').attr('aria-live',feedbackType==='error'?'assertive':'polite');
        feedback.find('span').text(String(message||''));
        feedback.prop('hidden',false);
        if(focus){feedback.trigger('focus');}
    }
    function saveDomainFlash(message,type){
        try{window.sessionStorage.setItem(domainFlashKey,JSON.stringify({message:String(message||''),type:String(type||'success'),created:Date.now()}));}catch(e){}
    }
    function restoreDomainFlash(){
        try{
            const raw=window.sessionStorage.getItem(domainFlashKey);
            if(!raw){return;}
            window.sessionStorage.removeItem(domainFlashKey);
            const flash=JSON.parse(raw);
            if(flash&&flash.message&&(!flash.created||Date.now()-parseInt(flash.created,10)<120000)){showDomainFeedback(flash.message,flash.type||'success',false);notify(flash.message,flash.type||'success');}
        }catch(e){}
    }
    restoreDomainFlash();
    const setButtons=(state)=>{
        $('#ilsm-start').prop('disabled',state==='running');
        $('#ilsm-pause').prop('disabled',state!=='running');
        $('#ilsm-resume').prop('disabled',!['paused','interrupted'].includes(state));
        $('#ilsm-cancel').prop('disabled',!['running','paused','interrupted'].includes(state));
        $('#ilsm-force-unlock').prop('disabled',!['running','paused','interrupted'].includes(state));
    };
    function stopScanStatusWatch(){
        scanStatusEpoch++;
        if(scanStatusTimer){window.clearTimeout(scanStatusTimer);scanStatusTimer=null;}
    }
    function renderScanProgress(d){
        const total=Math.max(0,parseInt(d&&d.total,10)||0);
        const scanned=Math.max(0,Math.min(total,parseInt(d&&d.scanned,10)||0));
        const percent=Math.max(0,Math.min(100,parseInt(d&&d.percent,10)||0));
        $('#ilsm-percent').text(percent+'%');
        $('#ilsm-scanned').text(scanned+' / '+total);
        $('.ilsm-progress span').css('width',percent+'%');
    }
    function syncScanStateAfterFailure(message){
        if(!scanId)return;
        active=false;
        pauseScanQuotes();
        stopScanStatusWatch();
        const epoch=scanStatusEpoch;
        let statusFailures=0;
        if(message){notify(message,'error');}
        $('#ilsm-status').text(ilsmT('checkingScanState','Checking current scan state…'));
        setButtons('running');

        function check(){
            if(epoch!==scanStatusEpoch||!scanId)return;
            post('ilsm_scan_status',{scan_id:scanId}).done(function(r){
                if(epoch!==scanStatusEpoch)return;
                if(!r||!r.success){
                    statusFailures++;
                    if(statusFailures<=3){scanStatusTimer=window.setTimeout(check,1500*statusFailures);return;}
                    $('#ilsm-status').text(ilsmT('scanStateUnknown','Scan state could not be confirmed'));
                    notify((r&&r.data&&r.data.message)||ilsmT('scanStateUnknownHelp','The browser could not confirm the scan state. No new batch was started.'),'error');
                    return;
                }
                statusFailures=0;
                const d=r.data||{},state=String(d.status||'').toLowerCase();
                renderScanProgress(d);
                if(state==='running'){
                    setButtons('running');
                    if(d.batch_in_flight){
                        $('#ilsm-status').text(ilsmT('finishingCurrentBatch','Finishing current batch…'));
                        scanStatusTimer=window.setTimeout(check,1800);
                        return;
                    }
                    if(token){
                        active=true;scanRetry=0;startScanQuotes();
                        $('#ilsm-status').text(ilsmT('running','Running'));
                        scanStatusTimer=window.setTimeout(function(){if(epoch===scanStatusEpoch){run();}},350);
                        return;
                    }
                    $('#ilsm-status').text(ilsmT('runningOtherSession','Running in another browser session'));
                    pauseScanQuotes();
                    return;
                }

                active=false;token='';setButtons(state||'idle');
                if(state==='paused'||state==='interrupted'){pauseScanQuotes();}else{stopScanQuotes();}
                if(state==='paused'){$('#ilsm-status').text(ilsmT('paused','Paused'));return;}
                if(state==='interrupted'){$('#ilsm-status').text(ilsmT('interrupted','Interrupted'));return;}
                if(state==='cancelled'){$('#ilsm-status').text(ilsmT('cancelled','Cancelled'));return;}
                if(state==='completed'){
                    $('#ilsm-status').text(ilsmT('completed','Completed'));
                    notify(ilsmT('scanCompleted','Scan completed successfully.'));
                    window.setTimeout(function(){window.location.assign(ILSM.dashboardUrl||'admin.php?page=ilsm-dashboard');},800);
                    return;
                }
                $('#ilsm-status').text(state?state.replace(/^./,m=>m.toUpperCase()):ilsmT('scanStateUnknown','Scan state could not be confirmed'));
            }).fail(function(xhr){
                if(epoch!==scanStatusEpoch)return;
                statusFailures++;
                if(statusFailures<=3){scanStatusTimer=window.setTimeout(check,1500*statusFailures);return;}
                const payload=xhr&&xhr.responseJSON&&xhr.responseJSON.data?xhr.responseJSON.data:{};
                $('#ilsm-status').text(ilsmT('scanStateUnknown','Scan state could not be confirmed'));
                notify((payload&&payload.message)||ilsmT('scanStateUnknownHelp','The browser could not confirm the scan state. No new batch was started.'),'error');
            });
        }
        check();
    }
    function run(){
        if(!scanId||!token||!active)return;
        $.ajax({url:ILSM.ajax,method:'POST',data:{action:'ilsm_scan_batch',nonce:ILSM.nonce,scan_id:scanId,token:token},timeout:30000}).done(function(r){
            if(!active){return;}
            scanRetry=0;
            if(!r.success){notify((r.data&&r.data.message)||ilsmT('scanFailed','Scan failed.'),'error');active=false;return;}
            const d=r.data||{};
            $('#ilsm-percent').text((d.percent||0)+'%');
            $('#ilsm-status').text(String(d.status||'running').replace(/^./,m=>m.toUpperCase()));
            $('#ilsm-scanned').text((d.scanned||0)+' / '+(d.total||0));
            $('.ilsm-progress span').css('width',(d.percent||0)+'%');
            if(!d.done&&d.status==='running'){setTimeout(run,Math.max(0,parseInt(ILSM.delay,10)||350));}
            else{active=false;setButtons(d.status);if(d.status==='paused'||d.status==='interrupted'){pauseScanQuotes();}else{stopScanQuotes();}if(d.status==='completed'){notify(ilsmT('scanCompleted','Scan completed successfully.'));setTimeout(function(){window.location.assign(ILSM.dashboardUrl||'admin.php?page=ilsm-dashboard');},800);}}
        }).fail(function(xhr,statusText){
            if(!active){return;}
            const payload=xhr&&xhr.responseJSON&&xhr.responseJSON.data?xhr.responseJSON.data:{};
            let message=payload&&payload.message?payload.message:(statusText==='timeout'?ilsmT('scanTimeout','Scan batch timed out after 30 seconds. Checking the server before another batch starts.'):ilsmT('scanRequestFailed','The scan request failed. Checking the current server state.'));
            const status=xhr&&xhr.status?parseInt(xhr.status,10):0;
            if(status&&!(payload&&payload.message)){message+=' (HTTP '+status+')';}
            syncScanStateAfterFailure(message);
        });
    }
    $('#ilsm-start').on('click',function(){
        stopScanStatusWatch();
        const firstScan=String($(this).data('first-scan'))==='1';
        const confirmation=firstScan?(ILSM.strings.confirmFirstScan||'Start your first website scan?'):(ILSM.strings.confirmRescan||'Start a fresh scan?');
        if(!window.confirm(confirmation))return;
        setButtons('running');
        startScanQuotes();
        $('#ilsm-status').text(ilsmT('startingFreshScan','Starting fresh scan…'));
        post('ilsm_start_scan',{fresh:1}).done(function(r){
            if(!r.success){stopScanQuotes();notify((r.data&&r.data.message)||ilsmT('couldNotStartScan','Could not start scan.'),'error');setButtons('idle');return;}
            scanId=r.data.scan_id;token=r.data.token;active=true;$('#ilsm-status').text(ilsmT('running','Running'));notify(ilsmT('freshScanStarted','Fresh scan started.'));run();
        }).fail(function(xhr){stopScanQuotes();const payload=xhr&&xhr.responseJSON&&xhr.responseJSON.data?xhr.responseJSON.data:{};const message=payload&&payload.message?payload.message:ilsmT('scanRequestFailed','The scan request failed. Checking the current server state.');notify(message,'error');if(payload&&payload.scan&&payload.scan.status){scanId=parseInt(payload.scan.id||scanId,10);setButtons(payload.scan.status);$('#ilsm-status').text(String(payload.scan.status).replace(/^./,m=>m.toUpperCase()));}else{setButtons('idle');}});
    });
    $('#ilsm-pause,#ilsm-resume,#ilsm-cancel').on('click',function(){
        stopScanStatusWatch();
        const action=this.id.replace('ilsm-','');
        if(!scanId){notify(ilsmT('startSessionScan','No controllable scan is available on this dashboard.'),'error');return;}
        if(action==='cancel'&&!window.confirm(ILSM.strings.confirmStop))return;
        post('ilsm_scan_action',{scan_id:scanId,scan_action:action,token:token}).done(function(r){
            if(!r.success){notify((r.data&&r.data.message)||ilsmT('actionFailed','Action failed.'),'error');return;}
            if(action==='pause'){active=false;token='';pauseScanQuotes();setButtons('paused');$('#ilsm-status').text(ilsmT('paused','Paused'));}
            if(action==='resume'){scanId=parseInt(r.data.scan_id||scanId,10);token=r.data.token||token;active=true;startScanQuotes();setButtons('running');$('#ilsm-status').text(ilsmT('running','Running'));run();}
            if(action==='cancel'){active=false;token='';stopScanQuotes();setButtons('cancelled');$('#ilsm-status').text(ilsmT('cancelled','Cancelled'));}
            if(r.data&&r.data.warning){notify(r.data.warning,'warning');}
        }).fail(function(xhr){
            const payload=xhr&&xhr.responseJSON&&xhr.responseJSON.data?xhr.responseJSON.data:{};
            notify((payload&&payload.message)||ilsmT('actionFailed','Action failed.'),'error');
        });
    });
    $('#ilsm-force-unlock').on('click',function(){
        stopScanStatusWatch();
        if(!window.confirm(ILSM.strings.confirmUnlock))return;
        post('ilsm_scan_action',{scan_id:scanId,scan_action:'force_unlock'}).done(function(r){
            if(!r.success){notify((r.data&&r.data.message)||ilsmT('unlockFailed','Unlock failed.'),'error');return;}
            active=false;token='';stopScanQuotes();setButtons('idle');$('#ilsm-status').text(ilsmT('interrupted','Interrupted'));notify((r.data&&r.data.message)||ilsmT('scanCleared','Interrupted scan cleared. You can run a fresh rescan.'));
        }).fail(function(xhr){notify(xhr&&xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message?xhr.responseJSON.data.message:'Unlock failed.','error');});
    });
    const initialState=ILSM.currentScan&&ILSM.currentScan.status?ILSM.currentScan.status:'idle';
    setButtons(initialState);
    if(initialState==='running'||initialState==='pending'){startScanQuotes();}
    else if(initialState==='paused'||initialState==='interrupted'){pauseScanQuotes();}
    else{stopScanQuotes();}

    let currentMapData=null;
    let baseViewBox=[0,0,900,520];
    const decodeEntities=value=>{const area=document.createElement('textarea');area.innerHTML=String(value||'');return area.value;};
    const esc=s=>String(decodeEntities(s)).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const issueColor=issue=>issue==='broken'?ILSM.mapColors.broken:(issue==='redirect'?ILSM.mapColors.redirect:null);
    const mapNodeColor=(node,fallback)=>issueColor(node.issue_type)||(String(node.object_type||'')==='external'?(ILSM.mapColors.external||'#AC2174'):fallback);
    function svgEl(tag,attrs,text){const el=document.createElementNS('http://www.w3.org/2000/svg',tag);Object.keys(attrs||{}).forEach(k=>el.setAttribute(k,attrs[k]));if(text!=null)el.textContent=text;return el;}
    function trimLabel(s,n=28){s=decodeEntities(s||'Untitled');return s.length>n?s.slice(0,n-1)+'…':s;}
    function draw(data){
        const svg=document.getElementById('ilsm-wheel'); if(!svg)return;
        currentMapData=data;
        while(svg.firstChild)svg.removeChild(svg.firstChild);
        if(!data.page){svg.appendChild(svgEl('text',{x:30,y:55,fill:'#94a3b8'},ILSM.strings.noData));return;}
        const style=$('#ilsm-map-style').val()||'wheel';
        const w=900,h=520,cx=450,cy=255;
        const limit=style==='compact'?6:12;
        const incoming=(data.incoming||[]).slice(0,limit), outgoing=(data.outgoing||[]).slice(0,limit);
        function activateNode(node){
            loadNodeMap(node);
        }
        function nodeGroup(node,x,y,color){
            const g=svgEl('g',{'class':'ilsm-node','data-post-id':node.id||0,'data-object-type':node.object_type||'post','data-node-url':node.url||'','role':'button','tabindex':'0'});
            const rect=svgEl('rect',{x:x-104,y:y-20,rx:18,width:208,height:40,fill:'#fff',stroke:color,'stroke-width':1.5});
            const label=svgEl('text',{x:x,y:y+4,'text-anchor':'middle','font-size':11,'class':'ilsm-node-label'},trimLabel(node.title||node.id));
            const action=(String(node.object_type||'post')==='post'&&parseInt(node.id||0,10))?'Click to load this page map':'Click to open this URL';
            const occurrences=Math.max(1,parseInt(node.occurrence_count||1,10));
            const title=svgEl('title',{},(node.title||ilsmT('unknownPage','Unknown page'))+'\nType: '+(node.object_type||'unresolved')+'\nAnchor: '+(node.anchor_text||'—')+'\nOccurrences: '+occurrences+'\n'+action);
            g.append(rect,label,title);
            if(occurrences>1){g.appendChild(svgEl('text',{x:x+91,y:y-10,'text-anchor':'end','font-size':9,'font-weight':700,fill:color},'×'+occurrences));}
            g.addEventListener('click',()=>activateNode(node));
            g.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();activateNode(node);}});
            svg.appendChild(g);
        }
        function edge(x1,y1,x2,y2,color,bad){
            const line=svgEl('line',{x1,y1,x2,y2,stroke:color,'stroke-width':2,'stroke-linecap':'round','class':'ilsm-edge'});
            if(bad)line.setAttribute('stroke-dasharray','7 5'); svg.appendChild(line);
        }
        function wheel(){
            function side(nodes,x,dir,color){
                const gap=nodes.length>1?380/(nodes.length-1):0;
                nodes.forEach((node,i)=>{const y=nodes.length===1?cy:70+i*gap;const bad=issueColor(node.issue_type);const c=mapNodeColor(node,color);edge(dir==='in'?x:cx,dir==='in'?y:cy,dir==='in'?cx:x,dir==='in'?cy:y,c,!!bad);nodeGroup(node,x,y,c);});
            }
            side(incoming,145,'in',ILSM.mapColors.incoming); side(outgoing,755,'out',ILSM.mapColors.outgoing);
        }
        function radial(){
            const all=incoming.map(n=>({n,c:mapNodeColor(n,ILSM.mapColors.incoming)})).concat(outgoing.map(n=>({n,c:mapNodeColor(n,ILSM.mapColors.outgoing)})));
            all.forEach((item,i)=>{const a=(Math.PI*2*i/Math.max(1,all.length))-Math.PI/2;const r=190;const x=cx+Math.cos(a)*r,y=cy+Math.sin(a)*r;edge(cx,cy,x,y,item.c,!!issueColor(item.n.issue_type));nodeGroup(item.n,x,y,item.c);});
        }
        function flow(){
            const drawCol=(nodes,x,color,direction)=>{const gap=nodes.length>1?390/(nodes.length-1):0;nodes.forEach((n,i)=>{const y=65+(nodes.length===1?190:i*gap);const c=mapNodeColor(n,color);if(direction==='incoming'){edge(x+104,y,cx-82,cy,c,!!issueColor(n.issue_type));}else{edge(cx+82,cy,x-104,y,c,!!issueColor(n.issue_type));}nodeGroup(n,x,y,c);});};
            drawCol(incoming,145,ILSM.mapColors.incoming,'incoming'); drawCol(outgoing,755,ILSM.mapColors.outgoing,'outgoing');
        }
        function compact(){
            const rows=Math.max(incoming.length,outgoing.length,1);const gap=Math.min(65,360/rows);
            incoming.forEach((n,i)=>{const y=90+i*gap,c=mapNodeColor(n,ILSM.mapColors.incoming);edge(249,y,365,cy,c,!!issueColor(n.issue_type));nodeGroup(n,145,y,c);});
            outgoing.forEach((n,i)=>{const y=90+i*gap,c=mapNodeColor(n,ILSM.mapColors.outgoing);edge(535,cy,651,y,c,!!issueColor(n.issue_type));nodeGroup(n,755,y,c);});
        }
        function organic(){
            const all=incoming.map((n,i)=>({n,c:mapNodeColor(n,ILSM.mapColors.incoming),seed:(n.id||i)+17})).concat(outgoing.map((n,i)=>({n,c:mapNodeColor(n,ILSM.mapColors.outgoing),seed:(n.id||i)+103})));
            all.forEach((item,i)=>{const a=(item.seed*2.399963229728653)+(i*0.31);const r=125+(i%4)*42;const x=cx+Math.cos(a)*r,y=cy+Math.sin(a)*Math.min(r,205);edge(cx,cy,x,y,item.c,!!issueColor(item.n.issue_type));nodeGroup(item.n,x,y,item.c);});
        }
        if(style==='radial')radial();else if(style==='flow')flow();else if(style==='compact')compact();else if(style==='organic')organic();else wheel();
        const halo=svgEl('circle',{cx:cx,cy:cy,r:88,fill:'none',stroke:'#dfe8f5','stroke-width':1});
        const ring=svgEl('circle',{cx:cx,cy:cy,r:77,fill:'#fff',stroke:ILSM.mapColors.incoming,'stroke-width':3,'class':'ilsm-center-ring'});
        const title=svgEl('text',{x:cx,y:cy-8,'text-anchor':'middle','font-size':17,'font-weight':700,fill:'#172033'},trimLabel(data.page.title,22));
        const score=svgEl('text',{x:cx,y:cy+20,'text-anchor':'middle','font-size':12,fill:'#64748b'},ilsmT('seoScore','SEO score')+' '+(data.page.seo_score||0));
        svg.append(halo,ring,title,score);
        const tree=['<ul><li><b><i class="fa fa-home"></i> '+esc(data.page.title)+'</b><ul><li><i class="fa fa-arrow-down"></i> '+esc(ilsmT('incoming','Incoming'))+'<ul>'];
        incoming.forEach(n=>tree.push('<li><button type="button" class="ilsm-tree-link" data-post-id="'+esc(n.id||0)+'" data-object-type="'+esc(n.object_type||'post')+'" data-node-url="'+esc(n.url||'')+'"><i class="fa fa-file-text-o"></i> '+esc(n.title||ilsmT('unknownPage','Unknown page'))+(parseInt(n.occurrence_count||1,10)>1?' <b class="ilsm-map-occurrences">×'+esc(n.occurrence_count)+'</b>':'')+'<small>'+esc(n.anchor_text||ilsmT('noAnchor','No anchor'))+'</small></button></li>'));
        tree.push('</ul></li><li><i class="fa fa-arrow-up"></i> '+esc(ilsmT('outgoing','Outgoing'))+'<ul>');
        outgoing.forEach(n=>tree.push('<li><button type="button" class="ilsm-tree-link'+(String(n.object_type||'')==='external'?' is-external':'')+'" data-post-id="'+esc(n.id||0)+'" data-object-type="'+esc(n.object_type||'unresolved')+'" data-node-url="'+esc(n.url||'')+'"><i class="fa fa-'+(String(n.object_type||'')==='external'?'external-link':(String(n.object_type||'')==='term'?'tags':(String(n.object_type||'')==='archive'?'folder-open':'file-text-o')))+'"></i> '+esc(n.title||ilsmT('unknownPage','Unknown page'))+(parseInt(n.occurrence_count||1,10)>1?' <b class="ilsm-map-occurrences">×'+esc(n.occurrence_count)+'</b>':'')+'<small>'+esc(n.anchor_text||ilsmT('noAnchor','No anchor'))+'</small></button></li>'));
        tree.push('</ul></li></ul></li></ul>');$('#ilsm-tree').html(tree.join(''));
        $('.ilsm-score').css('--score',parseInt(data.page.seo_score||0,10)).find('span').text(data.page.seo_score||0);
        window.setTimeout(fitMap,20);
        $('#ilsm-insight-metrics').html('<div><i class="fa fa-arrow-down"></i><span>'+esc(ilsmT('incoming','Incoming'))+'</span><b>'+esc(data.page.incoming_count||0)+'</b></div><div><i class="fa fa-arrow-up"></i><span>'+esc(ilsmT('outgoing','Outgoing'))+'</span><b>'+esc(data.page.outgoing_count||0)+'</b></div><div><i class="fa fa-font"></i><span>'+esc(ilsmT('weakAnchors','Weak anchors'))+'</span><b>'+esc(data.page.weak_anchor_count||0)+'</b></div>');
        const insightScore=Math.max(0,Math.min(100,parseInt(data.page.seo_score||0,10)));
        $('#ilsm-map-score').text(insightScore);
        $('#ilsm-map-score-progress').attr('stroke-dasharray',insightScore+' 100');
        $('#ilsm-map-insight-title').text(decodeEntities(data.page.title||ilsmT('noPageSelected','No page selected')));
        updateMapPageActions(data.page||{});
        $('#ilsm-map-insight-label').text(insightScore>=80?ilsmT('excellent','Excellent'):(insightScore>=60?ilsmT('good','Good'):ilsmT('needsWork','Needs work')));
        $('#ilsm-map-score-ring').attr('aria-label',ilsmFmt('seoScoreOutOf','SEO score: %s out of 100',insightScore));
        const rec=[];
        rec.push(parseInt(data.page.incoming_count||0,10)>0?'<li class="is-ok"><i class="fa fa-check-circle"></i>'+esc(ilsmT('hasIncoming','Page has incoming internal links.'))+'</li>':'<li class="is-warn"><i class="fa fa-exclamation-triangle"></i>'+esc(ilsmT('addIncoming','Add at least one relevant incoming link.'))+'</li>');
        rec.push(parseInt(data.page.outgoing_count||0,10)>=2?'<li class="is-ok"><i class="fa fa-check-circle"></i>'+esc(ilsmT('outgoingHealthy','Outgoing link coverage is healthy.'))+'</li>':'<li class="is-warn"><i class="fa fa-exclamation-triangle"></i>'+esc(ilsmT('addOutgoing','Add useful contextual outgoing links.'))+'</li>');
        rec.push(parseInt(data.page.weak_anchor_count||0,10)===0?'<li class="is-ok"><i class="fa fa-check-circle"></i>'+esc(ilsmT('anchorsDescriptive','Anchor text is descriptive.'))+'</li>':'<li class="is-warn"><i class="fa fa-exclamation-triangle"></i>'+esc(ilsmT('replaceGenericAnchor','Replace generic or empty anchor text.'))+'</li>');
        $('#ilsm-map-recommendations').html('<h3>'+esc(ilsmT('recommendations','Recommendations'))+'</h3><ul>'+rec.join('')+'</ul>');
    }
    function updateMapPageActions(page){
        const panel=$('#ilsm-map-page-actions');
        if(!panel.length)return;
        const url=String((page&&page.permalink)||'');
        const title=decodeEntities((page&&page.title)||'');
        const editUrl=String((page&&page.edit_url)||'');
        const opportunitiesUrl=String((page&&page.opportunities_url)||'');
        if(!url){panel.prop('hidden',true);return;}
        panel.prop('hidden',false);
        $('#ilsm-map-action-title').text(title);
        $('#ilsm-map-action-url').attr('href',url).text(url);
        $('#ilsm-map-open-url').attr('href',url);
        $('#ilsm-map-copy-url').attr('data-url',url);
        $('#ilsm-map-view-opportunities').attr('href',opportunitiesUrl||'#').prop('hidden',!opportunitiesUrl);
        $('#ilsm-map-edit-page').attr('href',editUrl||'#').prop('hidden',!editUrl);
        $('#ilsm-map-view-report').attr('href',String((page&&page.report_url)||'admin.php?page=ilsm-link-report'));
        updateMapDiagnostics((page&&page.diagnostics)||null);
    }
    function updateMapDiagnostics(diagnostics){
        const status=$('#ilsm-map-diagnosis-status');
        const title=$('#ilsm-map-diagnosis-title');
        const explanation=$('#ilsm-map-diagnosis-explanation');
        const action=$('#ilsm-map-diagnosis-action');
        const metrics=$('#ilsm-map-diagnostic-metrics');
        if(!status.length||!title.length||!metrics.length)return;
        if(!diagnostics){
            status.attr('class','ilsm-map-diagnosis__status is-neutral');
            title.text(ilsmT('noDiagnosis','No crawl diagnosis available'));
            explanation.text(ilsmT('runCompletedScan','Run a completed scan to calculate evidence for this page.'));
            action.text('');
            return;
        }
        const severity=String(diagnostics.severity||'good').replace(/[^a-z_-]/gi,'');
        status.attr('class','ilsm-map-diagnosis__status is-'+severity);
        title.text(decodeEntities(diagnostics.title||''));
        explanation.text(decodeEntities(diagnostics.explanation||''));
        action.text(decodeEntities(diagnostics.action||''));
        const m=diagnostics.metrics||{};
        metrics.html(
            '<div><dt>'+esc(ilsmT('contextualIncoming','Contextual incoming'))+'</dt><dd>'+esc(m.contextual_incoming||0)+'</dd></div>'+ 
            '<div><dt>'+esc(ilsmT('uniqueSourcePages','Unique source pages'))+'</dt><dd>'+esc(m.unique_sources||0)+'</dd></div>'+ 
            '<div><dt>'+esc(ilsmT('uniqueAnchors','Unique anchors'))+'</dt><dd>'+esc(m.unique_anchors||0)+'</dd></div>'+ 
            '<div><dt>'+esc(ilsmT('weakAnchors','Weak anchors'))+'</dt><dd>'+esc(m.weak_anchors||0)+'</dd></div>'+ 
            '<div><dt>'+esc(ilsmT('brokenLinks','Broken links'))+'</dt><dd>'+esc(m.broken_links||0)+'</dd></div>'+ 
            '<div><dt>'+esc(ilsmT('redirects','Redirects'))+'</dt><dd>'+esc(m.redirects||0)+'</dd></div>'+ 
            '<div><dt>'+esc(ilsmT('peerAvgIncoming','Peer avg. incoming'))+'</dt><dd>'+esc(m.peer_average||0)+'</dd></div>'
        );
    }
    function showNodeDetails(node){
        const drawer=$('#ilsm-node-drawer'); if(!drawer.length)return;
        const issue=node.issue_type||'';
        const status=issue?issue.replace(/_/g,' '):ilsmT('healthy','Healthy');
        const id=parseInt(node.id||0,10);
        const edit=id?'<a class="ilsm-btn ilsm-btn-primary" href="post.php?post='+encodeURIComponent(id)+'&action=edit"><i class="fa fa-pencil"></i> '+ilsmT('editPage','Edit page')+'</a>':'';
        const map=id?'<button type="button" class="ilsm-btn" id="ilsm-drawer-map" data-post-id="'+esc(id)+'"><i class="fa fa-sitemap"></i> '+esc(ilsmT('loadThisMap','Load this map'))+'</button>':'';
        $('#ilsm-node-details').html('<span class="ilsm-badge '+(issue?'is-warning':'is-success')+'">'+esc(status)+'</span><h3>'+esc(node.title||ilsmT('untitled','Untitled'))+'</h3><dl><div><dt>'+esc(ilsmT('anchorText','Anchor text'))+'</dt><dd>'+esc(node.anchor_text||ilsmT('noAnchorText','No anchor text'))+'</dd></div><div><dt>'+esc(ilsmT('postId','Post ID'))+'</dt><dd>'+esc(id||ilsmT('externalUnresolved','External or unresolved'))+'</dd></div></dl><div class="ilsm-drawer-actions">'+map+edit+'</div><p class="ilsm-muted">'+esc(ilsmT('mapClickHelp','Single click shows details. Double click opens the selected page map.'))+'</p>');
        drawer.addClass('is-open').attr('aria-hidden','false');
    }
    function zoomMap(factor){
        const svg=document.getElementById('ilsm-wheel');if(!svg)return;
        const current=(svg.getAttribute('viewBox')||baseViewBox.join(' ')).split(/\s+/).map(Number);if(current.length!==4)return;
        const [x,y,w,h]=current,nw=w*factor,nh=h*factor;svg.setAttribute('viewBox',[x+(w-nw)/2,y+(h-nh)/2,nw,nh].join(' '));
    }
    $(document).on('click','#ilsm-map-copy-url',function(){
        const button=$(this);const url=String(button.attr('data-url')||'');if(!url)return;
        const label=button.find('span');const original=ILSM.strings.copyUrl||label.text();
        const success=function(){label.text(ILSM.strings.copied||'Copied');window.setTimeout(function(){label.text(original);},1500);};
        if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(url).then(success).catch(function(){window.prompt(ILSM.strings.copyPrompt||'Copy this URL:',url);});}
        else{window.prompt(ILSM.strings.copyPrompt||'Copy this URL:',url);}
    });
    function activateLinkMapTab(){
        const tab=$('#ilsm-tab-link-map');
        const panel=$('#ilsm-panel-link-map');
        if(tab.length){
            $('.ilsm-map-tab').removeClass('is-active').attr('aria-selected','false');
            tab.addClass('is-active').attr('aria-selected','true');
        }
        if(panel.length){
            $('.ilsm-map-tabpanel').removeClass('is-active').prop('hidden',true);
            panel.addClass('is-active').prop('hidden',false);
        }
    }
    function updateLinkMapUrl(id){
        try{
            const url=new URL(window.location.href);
            // The dashboard also renders a compact Link Map. Loading that widget
            // must never turn the dashboard URL into a Visual Map URL in browser
            // history; otherwise a later reload (for example after a full scan)
            // navigates away from the scanner unexpectedly.
            if(url.searchParams.get('page')!=='ilsm-visual-map'){return;}
            url.searchParams.set('view','link-map');
            url.searchParams.set('post_id',String(id));
            window.history.replaceState({},'',url.toString());
        }catch(e){}
    }
    function clearMapForUnavailablePage(message){
        const svg=$('#ilsm-wheel');
        if(svg.length){svg.empty();}
        $('#ilsm-tree').html('<p class="ilsm-muted">'+esc(message||ilsmT('noScanPage','No scan data is available for this page.'))+'</p>');
        $('#ilsm-insight-metrics').html('');
        $('#ilsm-map-score').text('—');
        $('#ilsm-map-score-progress').attr('stroke-dasharray','0 100');
        $('#ilsm-map-insight-title').text(ilsmT('pageNotIncluded','Page not included in scan'));
        $('#ilsm-map-insight-label').text(ilsmT('freshScanRequired','Fresh scan required'));
        $('#ilsm-map-recommendations').html('<h3>'+esc(ilsmT('nextStep','Next step'))+'</h3><p>'+esc(message||ilsmT('enablePostTypeRescan','Enable this post type and run a fresh full scan.'))+'</p>');
        $('#ilsm-map-page-actions').prop('hidden',true);
        updateMapDiagnostics(null);
    }
    function loadMap(){
        const id=parseInt($('#ilsm-page-search').val()||0,10);
        if(!id){return;}
        post('ilsm_map_data',{post_id:id}).done(r=>{
            if(r.success){draw(r.data);updateLinkMapUrl(id);}
            else{const message=(r.data&&r.data.message)||'Map could not be loaded.';clearMapForUnavailablePage(message);notify(message,'error');}
        }).fail(xhr=>{
            const message=(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message)||'Map request failed.';
            clearMapForUnavailablePage(message);notify(message,'error');
        });
    }
    function loadNodeMap(node){
        const id=parseInt((node&&node.id)||0,10);
        const type=String((node&&node.object_type)||'post');
        const title=decodeEntities((node&&node.title)||('Post #'+id));
        if(type!=='post'||!id){
            const publicUrl=String((node&&node.url)||'');
            if(publicUrl){
                const opened=window.open(publicUrl,'_blank','noopener,noreferrer');
                if(opened){opened.opener=null;}
            }else{
                notify('This node is external, an archive, a taxonomy, or unresolved. It has no WordPress page link map.','error');
            }
            return;
        }
        const select=$('#ilsm-page-search');
        if(!select.find('option[value="'+id+'"]').length){
            select.append($('<option>',{value:String(id),text:title}));
        }
        select.val(String(id));
        activateLinkMapTab();
        $('#ilsm-node-drawer').removeClass('is-open').attr('aria-hidden','true');
        loadMap();
    }
    function syncMapControls(){
        const hasPage=parseInt($('#ilsm-page-search').val()||0,10)>0;
        $('#ilsm-load-map').prop('disabled',!hasPage).attr('aria-disabled',hasPage?'false':'true');
        return hasPage;
    }
    $('#ilsm-load-map').on('click',function(){if(syncMapControls()){loadMap();}});
    $('#ilsm-page-search').on('change',function(){if(syncMapControls()){loadMap();}});
    $('#ilsm-map-style').on('change',function(){if(syncMapControls()){loadMap();}});
    $(document).on('click','.ilsm-tree-link',function(){
        loadNodeMap({
            id:$(this).data('post-id'),
            object_type:$(this).data('object-type'),
            url:$(this).data('node-url'),
            title:$(this).clone().children().remove().end().text().trim()
        });
    });
    function fitMap(){
        const svg=document.getElementById('ilsm-wheel');
        if(!svg)return;
        const content=svg.querySelectorAll('.ilsm-node,.ilsm-edge,.ilsm-center-ring,circle,text');
        if(!content.length)return;
        let box;
        try{box=svg.getBBox();}catch(e){box=null;}
        if(!box||!box.width||!box.height){svg.setAttribute('viewBox','0 0 900 520');return;}
        const pad=Math.max(28,Math.min(90,Math.max(box.width,box.height)*0.08));
        baseViewBox=[box.x-pad,box.y-pad,box.width+pad*2,box.height+pad*2];
        svg.setAttribute('viewBox',baseViewBox.join(' '));
        svg.setAttribute('preserveAspectRatio','xMidYMid meet');
    }
    function setLargeMap(open){
        const panel=document.getElementById('ilsm-map-panel');
        if(!panel)return;
        panel.classList.toggle('is-large-map',open);
        document.documentElement.classList.toggle('ilsm-map-open',open);
        const button=document.getElementById('ilsm-big-map');
        if(button){button.setAttribute('aria-expanded',open?'true':'false');button.innerHTML=open?'<i class="fa fa-compress"></i>':'<i class="fa fa-expand"></i>';button.title=open?'Close large map':'Open large map';}
        window.setTimeout(fitMap,80);
    }
    $('#ilsm-fit-map').on('click',fitMap);
    $('#ilsm-zoom-in').on('click',()=>zoomMap(0.82));
    $('#ilsm-zoom-out').on('click',()=>zoomMap(1.22));
    $('#ilsm-map-filter').on('input',function(){const q=decodeEntities($(this).val()).toLowerCase();$('#ilsm-wheel .ilsm-node').each(function(){const hit=!q||$(this).text().toLowerCase().includes(q);$(this).toggleClass('is-dimmed',!hit).toggleClass('is-highlighted',!!q&&hit);});});
    $(document).on('click','.ilsm-drawer-close',function(){$('#ilsm-node-drawer').removeClass('is-open').attr('aria-hidden','true');if(ilsmDialogReturnFocus)ilsmDialogReturnFocus.focus();});
    $(document).on('click','#ilsm-drawer-map',function(){loadNodeMap({id:$(this).data('post-id'),object_type:'post',title:$('#ilsm-node-details h3').text()});});
    $('#ilsm-big-map').on('click',function(){const panel=document.getElementById('ilsm-map-panel');setLargeMap(panel?!panel.classList.contains('is-large-map'):false);});
    $(document).on('keydown',function(event){if(event.key==='Escape'&&document.getElementById('ilsm-map-panel')?.classList.contains('is-large-map'))setLargeMap(false);});
    if($('#ilsm-wheel').length&&syncMapControls()){loadMap();}
    $('.ilsm-table-search').on('input',function(){const q=$(this).val().toLowerCase();$('.ilsm-table tbody tr').each(function(){$(this).toggle($(this).text().toLowerCase().includes(q));});});
});

// Version 4.3: explicit confirmation for destructive scan-history actions.
document.addEventListener('submit', function (event) {
    var form = event.target.closest('.ilsm-confirm-form');
    if (!form) { return; }
    var message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});


// Link Opportunities interactions.
jQuery(function($){
    'use strict';

    function opportunityMessage(message, isError){
        var $box = $('#ilsm-opportunity-message');
        if(!$box.length){
            $box = $('<div id="ilsm-opportunity-message" class="ilsm-opportunity-message" aria-live="polite"></div>');
            $('.ilsm-opportunity-tabs').after($box);
        }
        $box.toggleClass('is-error', !!isError).text(message || '').attr('hidden', !message);
    }

    $(document).on('click', '.ilsm-opportunity-tab', function(){
        var view = String($(this).data('opportunity-view') || 'all');
        $('.ilsm-opportunity-tab').removeClass('is-active').attr('aria-selected', 'false');
        $(this).addClass('is-active').attr('aria-selected', 'true');
        $('.ilsm-opportunity-view').removeClass('is-active').attr('hidden', true);
        $('#ilsm-opportunity-view-' + view).addClass('is-active').removeAttr('hidden');
        opportunityMessage('', false);
    });

    $(document).on('change', '#ilsm-target-page', function(){
        var keyword = $(this).find('option:selected').data('keyword') || '';
        $('#ilsm-target-keyword').val(keyword);
    });

    $(document).on('input', '.ilsm-confidence-control input[type="range"]', function(){
        $(this).siblings('output').val(String(this.value) + '%');
    });

    $(document).on('click', '#ilsm-build-opportunities', function(){
        var $button = $(this);
        var $progress = $('#ilsm-opportunity-progress');
        var offset = 0;
        var created = 0;
        var checked = 0;
        var startedAt = Date.now();
        var elapsedTimer = null;
        var quoteTimer = null;
        var quoteIndex = 0;
        var $discovery = $('#ilsm-opportunity-discovery');
        var $bar = $discovery.find('.ilsm-opportunity-progress-track');
        var knownTotal = parseInt($discovery.attr('data-total') || 0, 10);
        var quotes = [
            ['A true passion that burns within your soul is one that can never be put out.','Zach Toelke'],
            ['No alarm clock needed. My passion wakes me.','Eric Thomas'],
            ['Our passion is our strength.','Billie Joe Armstrong'],
            ['Passion is energy. Feel the power that comes from focusing on what excites you.','Oprah Winfrey'],
            ['Passion is the genesis of genius.','Tony Robbins'],
            ['Chase your passion, not your pension.','Denis Waitley'],
            ['Develop a passion for learning.','Anthony J. D’Angelo'],
            ['Your passion is waiting for your courage to catch up.','Isabelle Lafleche'],
            ['Be the flame, not the moth.','Giacomo Casanova']
        ];
        function showQuote(){
            var quote = quotes[quoteIndex % quotes.length];
            $('#ilsm-opportunity-quote-text').text('“' + quote[0] + '”');
            $('#ilsm-opportunity-quote-author').text('— ' + quote[1]);
            quoteIndex++;
        }
        function elapsedText(){
            var seconds = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
            var minutes = Math.floor(seconds / 60);
            return 'Elapsed: ' + (minutes ? minutes + 'm ' : '') + (seconds % 60) + 's';
        }
        function updateDiscovery(data){
            var percent = Math.max(0, Math.min(100, parseInt(data.percent || 0, 10)));
            var total = parseInt(data.total || 0, 10);
            var stage = data.done ? 3 : (percent < 35 ? 1 : (percent < 70 ? 2 : 3));
            $('#ilsm-opportunity-progress-fill').css('width', percent + '%');
            $('.ilsm-opportunity-progress-ring').css('--ilsm-progress', percent * 3.6 + 'deg');
            $('#ilsm-opportunity-percent').text(percent + '%');
            $('#ilsm-opportunity-headline').text(data.done ? ('Analysis complete · ' + total.toLocaleString() + ' pages') : ('Analyzing ' + offset.toLocaleString() + (total ? ' of ' + total.toLocaleString() : '') + ' pages'));
            $('#ilsm-opportunity-candidates').text(checked.toLocaleString());
            $('#ilsm-opportunity-found').text(created.toLocaleString());
            $('#ilsm-opportunity-elapsed').text(elapsedText());
            $('#ilsm-opportunity-stage').text(data.done ? 'Opportunity index is ready.' : (percent < 10 ? 'Reading eligible page content…' : (percent < 35 ? 'Checking anchor relevance…' : (percent < 70 ? 'Verifying existing links and confidence…' : 'Running final insertion-safety checks…'))));
            $discovery.attr('data-stage', stage);
            $discovery.find('[data-opportunity-step]').each(function(){
                var step = parseInt($(this).attr('data-opportunity-step') || 0, 10);
                $(this).toggleClass('is-active', !data.done && step === stage).toggleClass('is-done', data.done || step < stage);
            });
            $bar.attr('aria-valuenow', percent);
        }
        $button.prop('disabled', true).attr('aria-busy', 'true').addClass('is-loading');
        opportunityMessage('', false);
        $progress.text(ilsmT('starting','Starting…'));
        $discovery.removeAttr('hidden').prop('hidden', false).css('display', 'block').removeClass('is-error is-complete').addClass('is-running');
        updateDiscovery({percent: 0, total: knownTotal, done: false});
        $('#ilsm-opportunity-stage').text('Preparing the first analysis batch…');
        showQuote();
        quoteTimer = window.setInterval(showQuote, 8000);
        elapsedTimer = window.setInterval(function(){ $('#ilsm-opportunity-elapsed').text(elapsedText()); }, 1000);

        function step(){
            $.post(ILSM.ajax, {
                action: 'ilsm_generate_opportunities',
                nonce: ILSM.nonce,
                offset: offset,
                batch: 6
            }).done(function(response){
                if(!response || !response.success){
                    var message = response && response.data && response.data.message ? response.data.message : ilsmT('opportunityGenerationFailed','Opportunity generation failed.');
                    opportunityMessage(message, true);
                    $progress.text(ilsmT('failedShort','Failed'));
                    window.clearInterval(elapsedTimer);
                    window.clearInterval(quoteTimer);
                    $discovery.removeClass('is-running').addClass('is-error');
                    $('#ilsm-opportunity-stage').text('Discovery stopped');
                    $button.prop('disabled', false).removeAttr('aria-busy').removeClass('is-loading');
                    return;
                }
                var data = response.data || {};
                offset = parseInt(data.offset || 0, 10);
                created += parseInt(data.created || 0, 10);
                checked += parseInt(data.checked || 0, 10);
                updateDiscovery(data);
                $progress.text((parseInt(data.percent || 0, 10)) + '% · ' + created + ' opportunities');
                if(!data.done){
                    window.setTimeout(step, 100);
                    return;
                }
                $progress.text(ilsmFmtN('completeOpportunities','Complete · %1$s opportunities from %2$s candidates',[created,checked]));
                window.clearInterval(elapsedTimer);
                window.clearInterval(quoteTimer);
                $discovery.removeClass('is-running').addClass('is-complete');
                opportunityMessage(created ? 'Opportunity index generated successfully.' : 'Analysis complete. No candidates met all current rules.', false);
                window.setTimeout(function(){ window.location.reload(); }, 700);
            }).fail(function(xhr){
                var message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : ilsmT('generationRequestFailed','The generation request failed.');
                opportunityMessage(message, true);
                $progress.text(ilsmT('failedShort','Failed'));
                window.clearInterval(elapsedTimer);
                window.clearInterval(quoteTimer);
                $discovery.removeClass('is-running').addClass('is-error');
                $('#ilsm-opportunity-stage').text('Discovery stopped');
                $button.prop('disabled', false).removeAttr('aria-busy').removeClass('is-loading');
            });
        }
        step();
    });


    $(document).on('click', '#ilsm-empty-regenerate', function(){
        $('#ilsm-build-opportunities').trigger('click');
    });
    $(document).on('click', '.ilsm-opportunity-status', function(){
        var $button = $(this);
        var $row = $button.closest('tr');
        var id = parseInt($row.data('opportunity-id') || 0, 10);
        var status = String($button.data('status') || '');
        if(!id || !status){ return; }
        $button.prop('disabled', true);
        $.post(ILSM.ajax, {
            action: 'ilsm_opportunity_status',
            nonce: ILSM.nonce,
            id: id,
            status: status
        }).done(function(response){
            if(response && response.success){
                $row.fadeOut(160, function(){ $(this).remove(); });
            } else {
                $button.prop('disabled', false);
                opportunityMessage(ilsmT('opportunityStatusFailed','The opportunity status could not be updated.'), true);
            }
        }).fail(function(){
            $button.prop('disabled', false);
            opportunityMessage(ilsmT('opportunityStatusRequestFailed','The opportunity status request failed.'), true);
        });
    });

    function escapeText(value){
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function renderTargetRows(rows, append){
        var $body = $('#ilsm-target-results');
        if(!append){ $body.empty(); }
        (rows || []).forEach(function(row){
            var manual = !!row.manual_only;
            var linked = manual ? '<span class="ilsm-badge is-warning">'+esc(ilsmT('manualEdit','Manual edit'))+'</span>' : (row.already_linked ? '<span class="ilsm-badge is-neutral">'+esc(ilsmT('alreadyLinked','Already linked'))+'</span>' : '<span class="ilsm-badge is-success">'+esc(ilsmT('readyAnchor','Ready anchor'))+'</span>');
            var editUrl = String(row.source_edit_url || '#');
            var signal = manual ? ilsmT('phraseNotPresent','Suggested phrase — not currently present') : String(row.signal || 'match').replace(/_/g, ' ');
            $body.append(
                '<tr>' +
                '<td><strong>' + escapeText(row.source_title) + '</strong><small class="ilsm-row-url">' + escapeText(row.source_type) + '</small></td>' +
                '<td><span class="ilsm-anchor-chip">' + escapeText(row.anchor) + '</span><small class="ilsm-signal-label">' + escapeText(signal) + '</small></td>' +
                '<td><p class="ilsm-opportunity-context">' + escapeText(row.context) + '</p></td>' +
                '<td><span class="ilsm-confidence">' + parseInt(row.score || 0, 10) + '%</span></td>' +
                '<td>' + linked + '</td>' +
                '<td><a class="ilsm-btn ilsm-btn-small ilsm-btn-primary" href="' + escapeText(editUrl) + '">Open source</a></td>' +
                '</tr>'
            );
        });
    }

    $(document).on('change', '#ilsm-target-page', function(){
        var $selected = $(this).find('option:selected');
        var keyword = String($selected.data('keyword') || '').trim();
        var url = String($selected.data('url') || '').trim();
        if(keyword && !String($('#ilsm-target-keyword').val() || '').trim()){
            $('#ilsm-target-keyword').val(keyword);
        }
        $('#ilsm-target-selected-url').text(url || ilsmT('wooExcluded','WooCommerce cart, checkout, account, login and thank-you URLs are excluded automatically.'));
    });

    $(document).on('click', '#ilsm-find-target-links', function(){
        var targetRef = String($('#ilsm-target-page').val() || '');
        var target = parseInt($('#ilsm-target-page option:selected').data('id') || 0, 10);
        var keyword = String($('#ilsm-target-keyword').val() || '').trim();
        var $button = $(this);
        var $progress = $('#ilsm-target-search-progress');
        if(!targetRef){
            opportunityMessage(ilsmT('chooseDestination','Choose a destination page or taxonomy URL first.'), true);
            return;
        }
        if(!keyword){
            opportunityMessage(ilsmT('enterKeyword','Enter a keyword or keyphrase for the destination.'), true);
            return;
        }

        var offset = 0;
        var found = 0;
        var first = true;
        var allRows = [];
        $button.prop('disabled', true).addClass('is-loading');
        opportunityMessage('', false);
        $('#ilsm-target-results').html('<tr><td colspan="6" class="ilsm-empty-cell"><span class="spinner is-active"></span> Searching the local crawl index…</td></tr>');
        $('#ilsm-target-result-summary').empty();

        function next(){
            $.post(ILSM.ajax, {
                action: 'ilsm_find_target_links',
                nonce: ILSM.nonce,
                target_ref: targetRef,
                target_id: target,
                keyword: keyword,
                post_type: $('#ilsm-target-source-type').val() || '',
                include_linked: $('#ilsm-target-include-linked').is(':checked') ? 1 : 0,
                offset: offset,
                batch: 20
            }).done(function(response){
                if(!response || !response.success){
                    var message = response && response.data && response.data.message ? response.data.message : ilsmT('focusedSearchFailed','The focused search failed.');
                    opportunityMessage(message, true);
                    $progress.text(ilsmT('failedShort','Failed'));
                    $button.prop('disabled', false).removeClass('is-loading');
                    return;
                }
                var data = response.data || {};
                var rows = data.results || [];
                allRows = allRows.concat(rows);
                first = false;
                found = allRows.length;
                offset = parseInt(data.offset || offset, 10);
                $progress.text((parseInt(data.percent || 0, 10)) + '% searched · ' + found + ' matches');
                $('#ilsm-target-result-summary').text(ilsmFmtN('destinationSummary','Destination: %1$s · %2$s · Keyword: %3$s',[data.target_title||'',data.target_url||'',data.keyword||keyword]));
                if(!data.done){
                    window.setTimeout(next, 80);
                    return;
                }
                var deduped = {};
                allRows.forEach(function(row){var key=String(row.source_id||0)+'|'+String(row.source_type||'')+'|'+String(row.source_title||'');if(!deduped[key]||parseInt(row.score||0,10)>parseInt(deduped[key].score||0,10)){deduped[key]=row;}});
                allRows = Object.keys(deduped).map(function(key){return deduped[key];}).sort(function(a,b){return parseInt(b.score||0,10)-parseInt(a.score||0,10);}).slice(0,75);
                found = allRows.length;
                renderTargetRows(allRows,false);
                $progress.text('100% searched · '+found+' matches');
                if(!found){
                    $('#ilsm-target-results').html('<tr><td colspan="6" class="ilsm-empty-cell">No safe body-text opportunities were found for this destination and keyword.</td></tr>');
                }
                $button.prop('disabled', false).removeClass('is-loading');
            }).fail(function(xhr){
                var message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : ilsmT('focusedSearchRequestFailed','The focused search request failed.');
                opportunityMessage(message, true);
                $progress.text(ilsmT('failedShort','Failed'));
                $button.prop('disabled', false).removeClass('is-loading');
            });
        }
        next();
    });

    if(new URLSearchParams(window.location.search).has('target_post_id')){
        $('.ilsm-opportunity-tab[data-opportunity-view="target"]').trigger('click');
        $('#ilsm-target-selected-url').text(String($('#ilsm-target-page option:selected').data('url')||''));
    }
});

// Settings: restore documented safe defaults without submitting automatically.
jQuery(function($){
    'use strict';
    $(document).on('click','#ilsm-reset-settings',function(){
        if(!window.confirm(ilsmT('resetDefaultsConfirm','Reset these fields to the plugin defaults? Click Save Settings to apply them.'))){return;}
        var $form=$(this).closest('form');
        $form.find('[name="batch_size"]').val('15');
        $form.find('[name="batch_delay"]').val('350');
        $form.find('[name="max_pages"]').val('5000');
        $form.find('[name="report_per_page"]').val('50');
        $form.find('[name="exclude_media_links"]').prop('checked',true);
        $form.find('[name="check_http"],[name="delete_on_uninstall"]').prop('checked',false);
        $form.find('[name="post_types[]"]').each(function(){ $(this).prop('checked', String($(this).data('ilsm-default')) === '1'); });
        var colors={incoming_color:'#2563EB',outgoing_color:'#F97316',broken_color:'#EF4444',redirect_color:'#8B5CF6'};
        $.each(colors,function(name,value){$form.find('[name="'+name+'"]').val(value).trigger('input').siblings('code').text(value);});
    });
});
// Premium searchable destination selector for Link Opportunities.
jQuery(function($){
    'use strict';
    var $select=$('#ilsm-target-page');
    if(!$select.length||$select.data('ilsm-smart-ready')){return;}
    $select.data('ilsm-smart-ready',true).addClass('ilsm-native-select-hidden');
    var $wrap=$('<div class="ilsm-smart-select"></div>');
    var $button=$('<button type="button" class="ilsm-smart-select-button" aria-haspopup="listbox" aria-expanded="false"><span class="ilsm-smart-select-value">'+esc(ilsmT('selectScannedPage','Select a scanned page'))+'</span><i class="fa fa-chevron-down" aria-hidden="true"></i></button>');
    var $panel=$('<div class="ilsm-smart-select-panel" hidden></div>');
    var $search=$('<label class="ilsm-smart-select-search"><i class="fa fa-search" aria-hidden="true"></i><input type="search" autocomplete="off" placeholder="'+esc(ilsmT('searchContent','Search pages, posts or trips'))+'"></label>');
    var $list=$('<div class="ilsm-smart-select-list" role="listbox" tabindex="-1"></div>');
    $panel.append($search,$list);$wrap.append($button,$panel);$select.after($wrap);

    function parseType(text){var m=String(text||'').match(/\[([^\]]+)\]\s*$/);return m?m[1]:'content';}
    function cleanTitle(text){return String(text||'').replace(/\s*\[[^\]]+\]\s*$/,'');}
    function build(filter){
        filter=String(filter||'').toLowerCase();$list.empty();var found=0;
        $select.find('option').each(function(index){
            if(!index){return;}
            var $option=$(this),text=$option.text(),title=cleanTitle(text),type=parseType(text),hay=(title+' '+type).toLowerCase();
            if(filter&&hay.indexOf(filter)===-1){return;}
            var $item=$('<button type="button" class="ilsm-smart-select-option" role="option"></button>');
            $item.attr('data-value',$option.val()).append($('<span class="ilsm-smart-select-title"></span>').text(title),$('<span class="ilsm-smart-select-type"></span>').text(type));
            if(String($select.val())===String($option.val())){$item.addClass('is-selected').attr('aria-selected','true');}
            $list.append($item);found++;
        });
        if(!found){$list.append('<div class="ilsm-smart-select-empty">'+esc(ilsmT('noMatchingContent','No matching scanned content found.'))+'</div>');}
    }
    function open(){build($search.find('input').val());$panel.prop('hidden',false);$button.attr('aria-expanded','true').addClass('is-open');window.setTimeout(function(){$search.find('input').trigger('focus');},0);}
    function close(){$panel.prop('hidden',true);$button.attr('aria-expanded','false').removeClass('is-open');}
    $button.on('click',function(){if($panel.prop('hidden')){open();}else{close();}});
    $search.on('input','input',function(){build(this.value);});
    $list.on('click','.ilsm-smart-select-option',function(){var value=$(this).data('value');$select.val(String(value)).trigger('change');$button.find('.ilsm-smart-select-value').text(cleanTitle($select.find('option:selected').text()));close();$button.trigger('focus');});
    $(document).on('click.ilsmSmartSelect',function(event){if(!$wrap.is(event.target)&&!$wrap.has(event.target).length){close();}});
    $wrap.on('keydown',function(event){if(event.key==='Escape'){close();$button.trigger('focus');}});
    $select.on('change',function(){var text=$select.find('option:selected').text();$button.find('.ilsm-smart-select-value').text($select.val()?cleanTitle(text):'Select a scanned page');});
});
// Secure Link Opportunities preview, insertion, bulk processing and undo.
jQuery(function($){
    'use strict';
    var pendingIds=[], previewById={}, isDryRun=$('.ilsm-mode-badge').hasClass('is-dry-run'), ilsmDialogReturnFocus=null, historyStatus='all';
    function escapeText(value){return $('<div>').text(value==null?'':String(value)).html();}
    function request(action,data,timeout){return $.ajax({url:ILSM.ajax,method:'POST',dataType:'json',timeout:timeout||30000,data:$.extend({action:action,insert_nonce:ILSM.insertNonce},data||{})});}
    function modal(open,mode){var modalEl=document.getElementById('ilsm-insert-modal');if(!modalEl){message(ilsmT('dialogUnavailable','The insertion dialog is unavailable. Reload this page and try again.'),true);return;}if(open){ilsmDialogReturnFocus=document.activeElement;}$(modalEl).prop('hidden',!open);if(open){$('#ilsm-insert-modal').removeAttr('data-result-state');if(mode==='preview'){$('#ilsm-insert-modal-title').text(ilsmT('previewOpportunityTitle','Preview internal-link opportunity'));$('.ilsm-modal-titlebar p').text(ilsmT('reviewPreview','Review the proposed source, anchor, destination and insertion context. No content will be changed.'));}else if(mode==='confirm'){$('#ilsm-insert-modal-title').text(ilsmT('confirmInsertionTitle','Confirm internal-link insertion'));$('.ilsm-modal-titlebar p').text(ilsmT('reviewLive','Review the verified source, anchor and destination before writing the link.'));}$('#ilsm-insert-modal .ilsm-modal-close').trigger('focus');}else if(ilsmDialogReturnFocus){ilsmDialogReturnFocus.focus();}}
    function message(text,error){$('#ilsm-bulk-progress').text(text||'').toggleClass('is-error',!!error);}
    function rowFor(id){return $('tr[data-opportunity-id="'+parseInt(id,10)+'"]');}
    function currentOpportunityStatus(){try{return new URL(window.location.href).searchParams.get('status')||'new';}catch(e){return 'new';}}
    function finalizeInsertedRow(id){var row=rowFor(id);if(!row.length){return;}var status=currentOpportunityStatus();if(status==='new'){row.fadeOut(180,function(){$(this).remove();});return;}row.find('.ilsm-insertion-state').text(ilsmT('inserted','Inserted'));row.find('.ilsm-opportunity-check').prop('checked',false).prop('disabled',true);row.find('.ilsm-insert-opportunity,.ilsm-preview-opportunity').remove();}
    function removeProcessedRow(id){var row=rowFor(id);if(!row.length){return;}row.find('.ilsm-opportunity-check').prop('checked',false).prop('disabled',true);row.fadeOut(180,function(){$(this).remove();});}
    function updateHistoryCounts(counts){counts=counts||{};$('.ilsm-history-tab[data-history-status="all"] span').text(parseInt(counts.all||0,10));$('.ilsm-history-tab[data-history-status="live"] span').text(parseInt(counts.live||0,10));$('.ilsm-history-tab[data-history-status="errors"] span').text(parseInt(counts.errors||0,10));}
    function loadHistory(page,append){page=Math.max(1,parseInt(page||1,10));var $button=$('#ilsm-history-load-more'),$status=$('#ilsm-history-status');$button.prop('disabled',true);$status.text(ilsmT('loadingHistory','Loading history…'));request('ilsm_insertion_history',{history_status:historyStatus,history_page:page},30000).done(function(r){if(!r||!r.success){$status.text((r&&r.data&&r.data.message)||ilsmT('historyLoadFailed','History could not be loaded.'));return;}var data=r.data||{},$body=$('#ilsm-insertion-history-body');if(append){$body.find('.ilsm-history-empty').remove();$body.append(data.html||'');}else{$body.html(data.html||'');}$button.attr('data-history-page',page).prop('disabled',!data.has_more);updateHistoryCounts(data.counts);$status.text(data.has_more?ilsmT('moreHistoryAvailable','More history is available.'):'');}).fail(function(xhr,status){$status.text(responseError(xhr,status==='timeout'?ilsmT('historyLoadTimeout','History loading timed out.'):ilsmT('historyLoadFailed','History could not be loaded.')));});}
    function linkButton(url,label){return url?'<a class="ilsm-btn ilsm-btn-small" href="'+escapeText(url)+'" target="_blank" rel="noopener noreferrer">'+escapeText(label)+'</a>':'';}
    function locationText(item){var value=item.content_location;if(value===null||typeof value==='undefined'||value===''){return item.editor_type==='elementor'?'Elementor text widget':'Post content';}if(typeof value==='object'){try{return JSON.stringify(value);}catch(e){return 'Editor content';}}return String(value);}
    function previewHtml(item){
        var warnings=(item.warnings||[]).map(function(w){return '<li>'+escapeText(w)+'</li>';}).join('');
        return '<article class="ilsm-preview-item" data-preview-id="'+parseInt(item.opportunity_id,10)+'"><div class="ilsm-preview-grid"><p><strong>Source:</strong> '+escapeText(item.source_title)+'</p><p><strong>Anchor:</strong> '+escapeText(item.anchor)+'</p><p><strong>Destination:</strong> '+escapeText(item.target_title)+'</p><p><strong>Insert location:</strong> '+escapeText(locationText(item))+'</p><p><strong>Editor:</strong> '+escapeText(item.editor_type)+'</p><p><strong>Confidence:</strong> '+parseInt(item.score||0,10)+'% <span class="ilsm-required-confidence">(required: '+parseInt(item.minimum_confidence||0,10)+'%)</span></p></div><div class="ilsm-preview-paragraph">'+String(item.paragraph_html||'')+'</div>'+(item.insertable===false?'<div class="ilsm-preview-ineligible" role="status"><strong>Not currently insertable.</strong> '+escapeText(item.reason_message||'This opportunity no longer passes validation.')+'</div>':'')+(warnings?'<ul class="ilsm-preview-warnings">'+warnings+'</ul>':'')+'<div class="ilsm-result-actions">'+linkButton(item.source_edit_url,'Edit source')+linkButton(item.source_view_url,'View source')+linkButton(item.destination_url,'Open destination')+'</div></article>';
    }
    function responseError(xhr,fallback){var data=xhr&&xhr.responseJSON&&xhr.responseJSON.data;return data&&data.message?String(data.message):fallback;}
    function previewModeNotice(){
        var settingsUrl=ILSM.settingsUrl||'admin.php?page=ilsm-settings#ilsm-safe-link-insertion';
        var enableButton=ILSM.canManageSettings?'<button type="button" class="ilsm-btn ilsm-btn-primary ilsm-enable-live-from-result">Enable Live Mode</button>':'';
        return '<aside class="ilsm-preview-mode-help" role="note"><div class="ilsm-preview-mode-help-icon" aria-hidden="true">i</div><div class="ilsm-preview-mode-help-content"><strong>Preview Mode is enabled</strong><p>No post content was changed. To write approved links, open <strong>Settings → Safe Link Insertion</strong>, clear <strong>Preview Mode</strong>, save the settings, then run Insert Selected Links again.</p><div class="ilsm-preview-mode-help-actions">'+enableButton+'<a class="ilsm-btn" href="'+escapeText(settingsUrl)+'">Open insertion settings</a></div></div></aside>';
    }
    function loadPreviews(ids,then,onProgress){if(typeof onProgress==='function'){onProgress(0,ids.length);}request('ilsm_opportunity_bulk_preview',{ids:ids},60000).done(function(r){if(r&&r.success){if(typeof onProgress==='function'){onProgress(parseInt(r.data.checked||ids.length,10),ids.length);}then({items:r.data.items||[],failures:r.data.failures||[]});return;}then({items:[],failures:[{id:0,message:(r&&r.data&&r.data.message)||ilsmT('safetyPreviewFailed','Safety preview failed.')}]});}).fail(function(xhr,status){then({items:[],failures:[{id:0,message:responseError(xhr,status==='timeout'?ilsmT('safetyPreviewTimeout','Safety preview timed out after 60 seconds.'):ilsmT('safetyPreviewFailed','Safety preview failed.'))} ]});});}
    function failureHtml(failures){if(!failures.length){return '';}return '<div class="ilsm-preview-skipped"><strong>'+failures.length+' could not be prepared</strong><ul>'+failures.map(function(f){return '<li><strong>#'+parseInt(f.id,10)+'</strong>: '+escapeText(f.message)+' <code>'+escapeText(f.code||'preview_failed')+'</code></li>';}).join('')+'</ul></div>';}
    function resultHtml(results){var inserted=0,previewed=0,skipped=0,failed=0;results.forEach(function(r){if(r.status==='inserted'){inserted++;}else if(r.status==='dry_run_passed'){previewed++;}else if(r.status==='skipped'){skipped++;}else{failed++;}});var summary=isDryRun?(previewed+' previewed · '+skipped+' skipped · '+failed+' failed'):(inserted+' inserted · '+skipped+' skipped · '+failed+' failed');var heading=isDryRun?'Preview results':'Link insertion results';return '<div class="ilsm-insert-summary"><h3>'+heading+'</h3><p><strong>'+summary+'</strong></p>'+(isDryRun?previewModeNotice():'')+results.map(function(r){var cls=(r.status==='inserted'||r.status==='dry_run_passed')?'is-success':(r.status==='skipped'?'is-warning':'is-error'),icon=cls==='is-success'?'✓':(cls==='is-warning'?'!':'×');return '<article class="ilsm-insert-result '+cls+'"><span class="ilsm-result-icon" aria-hidden="true">'+icon+'</span><div class="ilsm-result-content"><p class="ilsm-result-route"><strong>'+escapeText(r.source_title||('Opportunity #'+r.id))+'</strong><span class="ilsm-route-arrow" aria-hidden="true">→</span><span>'+escapeText(r.target_title||'Destination')+'</span></p><p class="ilsm-result-anchor">Anchor: <span class="ilsm-anchor-chip">'+escapeText(r.anchor||'')+'</span></p><p class="ilsm-result-message"><strong>'+escapeText(r.label)+'</strong>: '+escapeText(r.message||'No details returned.')+'</p><div class="ilsm-result-actions">'+linkButton(r.source_edit_url,r.status==='inserted'?'Edit where added':'Edit source')+linkButton(r.source_view_url,'View source page')+linkButton(r.destination_url,'Open destination')+'</div></div></article>';}).join('')+'</div>';}
    $(document).off('click.ilsmOpportunityPreview','.ilsm-preview-opportunity,.ilsm-insert-opportunity').on('click.ilsmOpportunityPreview','.ilsm-preview-opportunity,.ilsm-insert-opportunity',function(event){
        event.preventDefault();
        event.stopPropagation();
        var $button=$(this),insert=$button.hasClass('ilsm-insert-opportunity');
        var id=parseInt($button.attr('data-opportunity-id')||$button.closest('tr').attr('data-opportunity-id')||0,10);
        if(!id){message('This opportunity could not be identified. Regenerate opportunities and try again.',true);return;}
        if($button.prop('disabled')||$button.hasClass('is-loading')){return;}
        $button.addClass('is-loading').prop('disabled',true).attr('aria-busy','true');
        message(ilsmT('preparingPreview','Preparing insertion preview…'),false);
        loadPreviews([id],function(result){
            $button.removeClass('is-loading').prop('disabled',false).removeAttr('aria-busy');
            if(!result.items.length){
                var failure=result.failures&&result.failures[0]?result.failures[0]:{};
                message(failure.message||ilsmT('previewFailedChanged','Preview failed. Regenerate opportunities if the source content has changed.'),true);
                return;
            }
            var item=result.items[0];previewById={};previewById[id]=item;pendingIds=insert&&item.insertable!==false?[id]:[];
            $('#ilsm-insert-preview').html(previewHtml(item));
            var canConfirm=insert&&item.insertable!==false&&!!item.snapshot_token;
            $('#ilsm-confirm-insert').prop('checked',false).toggle(canConfirm);
            $('#ilsm-confirm-insert-button').prop('disabled',true).toggle(canConfirm).text(isDryRun?'Run verified preview':'Insert verified link');
            modal(true,insert?'confirm':'preview');
            if(insert&&!canConfirm){$('#ilsm-insert-modal-title').text(ilsmT('internalLinkNotInsertable','Internal link not insertable'));$('.ilsm-modal-titlebar p').text(item.reason_message||ilsmT('noLongerPassesChecks','This opportunity no longer passes the current insertion checks.'));$('#ilsm-insert-modal').attr('data-result-state','warning');}
            message('',false);
        });
    });
    $('#ilsm-select-all-opportunities').on('change',function(){$('.ilsm-opportunity-check:not(:disabled)').prop('checked',this.checked);});
    function bulkStatusHtml(ids){return '<div class="ilsm-live-bulk"><p class="ilsm-live-progress" aria-live="polite"><strong>Checking selected opportunities 0 / '+ids.length+'…</strong></p><div class="ilsm-live-results">'+ids.map(function(id){var row=rowFor(id),source=row.find('td').eq(1).text().trim()||('Opportunity #'+id),anchor=row.find('td').eq(2).text().trim();return '<article class="ilsm-live-row is-pending" data-live-id="'+id+'"><span class="ilsm-live-icon" aria-hidden="true">…</span><div><strong>'+escapeText(source)+'</strong><br><small>'+escapeText(anchor)+'</small><p class="ilsm-live-message">Waiting for safety check…</p><div class="ilsm-result-actions"></div></div></article>';}).join('')+'</div></div>';}
    function updateLive(id,status,text,preview){var $item=$('.ilsm-live-row[data-live-id="'+parseInt(id,10)+'"]'),icons={checking:'↻',inserting:'↻',inserted:'✓',dry_run_passed:'✓',skipped:'!',failed:'×'},labels={checking:'Checking…',inserting:isDryRun?'Testing…':'Inserting…',inserted:'Inserted',dry_run_passed:'Preview passed',skipped:'Skipped',failed:'Failed'};$item.removeClass('is-pending is-checking is-inserting is-inserted is-skipped is-failed').addClass('is-'+status);$item.find('.ilsm-live-icon').text(icons[status]||'•');$item.find('.ilsm-live-message').html('<strong>'+escapeText(labels[status]||status)+'</strong>'+ (text?': '+escapeText(text):''));if(preview){$item.find('.ilsm-result-actions').html(linkButton(preview.source_edit_url,isDryRun?'Edit source':'Edit where added')+linkButton(preview.source_view_url,'View source')+linkButton(preview.destination_url,'Open destination'));}}
    function runAutomaticInsert(items,failures,total){previewById={};items.forEach(function(item){previewById[parseInt(item.opportunity_id,10)]=item;});var initialSkipped=0,initialFailed=0;(failures||[]).forEach(function(f){var state=f.status==='skipped'?'skipped':'failed';if(state==='skipped'){initialSkipped++;}else{initialFailed++;}updateLive(f.id,state,f.message||ilsmT('safetyPreviewFailed','Safety preview failed.'));removeProcessedRow(f.id);});var queue=items.slice(),processed=(failures||[]).length,inserted=0,skipped=initialSkipped,failed=initialFailed;function finish(){var successLabel=isDryRun?'Preview passed':'Inserted',summary=isDryRun?(inserted+' previews passed · '+skipped+' skipped · '+failed+' failed'):(inserted+' inserted · '+skipped+' skipped · '+failed+' failed'),verified=failed===0;$('.ilsm-live-progress').replaceWith('<div class="ilsm-live-summary-grid"><div class="is-success"><span>✓</span><strong>'+inserted+'</strong><small>'+successLabel+'</small></div><div class="is-neutral"><span>−</span><strong>'+skipped+'</strong><small>Skipped</small></div><div class="is-error"><span>×</span><strong>'+failed+'</strong><small>Failed</small></div><div class="is-verified"><span>♢</span><strong>'+(verified?'All links verified':'Review required')+'</strong><small>Source, anchor & destination</small></div></div>');var modalTitle,modalDescription;if(isDryRun){modalTitle=failed?'Preview completed with issues':'Internal-link preview complete';modalDescription='The selected opportunities were checked without changing content.';}else if(failed&&inserted){modalTitle='Insertion completed with errors';modalDescription='Successful links moved to Live history. Failed attempts moved to Errors.';}else if(failed&&!inserted){modalTitle='Internal-link insertion failed';modalDescription='Failed attempts moved to Errors history with their real reason.';}else if(skipped&&inserted){modalTitle='Internal links partially inserted';modalDescription='Successful links moved to Live history. Skipped attempts are available for review.';}else{modalTitle='Internal links inserted';modalDescription='The links are now live and appear in Live history.';}$('#ilsm-insert-modal-title').text(modalTitle);$('.ilsm-modal-titlebar p').text(modalDescription);$('#ilsm-insert-modal').attr('data-result-state',failed?'error':(skipped?'warning':'success'));message('Finished: '+summary,failed>0);$('#ilsm-select-all-opportunities').prop('checked',false);loadHistory(1,false);$('[data-ilsm-close],.ilsm-modal-close').prop('disabled',false);$('[data-ilsm-close]').text(ilsmT('close','Close'));$('#ilsm-confirm-insert,#ilsm-confirm-insert-button').hide();}function next(){if(!queue.length){finish();return;}var item=queue.shift(),id=parseInt(item.opportunity_id,10);updateLive(id,'inserting','',item);request('ilsm_opportunity_insert_fresh',{id:id},45000).done(function(r){var data=r&&r.data?r.data:{},status=r&&r.success?(data.status||'inserted'):'failed';if(status==='inserted'){inserted++;updateLive(id,'inserted',data.message||'The link is now live. It was inserted, saved, and verified successfully.',data);removeProcessedRow(id);}else if(status==='dry_run_passed'){inserted++;updateLive(id,'dry_run_passed',data.message||'Preview passed. No content was changed.',data);}else if(status==='skipped'){skipped++;updateLive(id,'skipped',data.message||'No content was changed.',data);removeProcessedRow(id);}else{failed++;updateLive(id,'failed',data.message||'Insertion failed.',data);removeProcessedRow(id);}processed++;$('.ilsm-live-progress strong').text((isDryRun?'Testing':'Inserting')+' links '+processed+' / '+total+'…');next();}).fail(function(xhr,status){failed++;processed++;updateLive(id,'failed',responseError(xhr,status==='timeout'?'The insertion timed out. Check the source page before retrying.':'The insertion request failed.'),item);$('.ilsm-live-progress strong').text((isDryRun?'Testing':'Inserting')+' links '+processed+' / '+total+'…');next();});}next();}
    function startBulkInsertion(requested){$('#ilsm-insert-preview').html(bulkStatusHtml(requested)+(isDryRun?previewModeNotice():''));$('#ilsm-confirm-insert,#ilsm-confirm-insert-button').hide();$('[data-ilsm-close],.ilsm-modal-close').prop('disabled',true);modal(true);message(ilsmFmtN('checkingSelected','Checking selected opportunities %1$s / %2$s…',[0,requested.length]),false);requested.forEach(function(id){updateLive(id,'checking',ilsmT('safetyPreviewProgress','Safety preview in progress.'));});loadPreviews(requested,function(result){var items=result.items||[],failures=result.failures||[];items.filter(function(item){return item.insertable===false;}).forEach(function(item){failures.push({id:item.opportunity_id,message:item.reason_message||ilsmT('notCurrentlyInsertable','This opportunity is not currently insertable.'),code:item.reason_code||'not_insertable'});});items=items.filter(function(item){return item.insertable!==false&&item.snapshot_token;});var checked=items.length+failures.length;$('.ilsm-live-progress strong').text(ilsmFmtN('safetyCheckCompleteAction','Safety check complete %1$s / %2$s. %3$s verified links…',[checked,requested.length,isDryRun?ilsmT('testing','Testing…').replace('…',''):ilsmT('inserting','Inserting…').replace('…','')]));message(ilsmFmtN('safetyCheckComplete','Safety check complete %1$s / %2$s.',[checked,requested.length]),false);runAutomaticInsert(items,failures,requested.length);},function(done,total){$('.ilsm-live-progress strong').text(ilsmFmtN('checkingSelected','Checking selected opportunities %1$s / %2$s…',[done,total]));message(ilsmFmtN('checkingSelected','Checking selected opportunities %1$s / %2$s…',[done,total]),false);});}
    $('#ilsm-insert-selected').on('click',function(){var requested=$('.ilsm-opportunity-check:checked').map(function(){return parseInt($(this).closest('tr').data('opportunity-id'),10);}).get();if(!requested.length){message(ilsmT('selectEligible','Select at least one eligible opportunity.'),true);return;}if(isDryRun){if(!window.confirm(ilsmT('previewLiveConfirm','Preview Mode is active, so no links will be added. Switch to Live Mode and write the selected links into content now? A WordPress revision will be created when enabled in Settings.'))){startBulkInsertion(requested);return;}message(ilsmT('enablingLiveMode','Enabling Live Mode…'),false);request('ilsm_enable_live_insertion',{},15000).done(function(r){if(!r||!r.success){message((r&&r.data&&r.data.message)||ilsmT('liveModeEnableFailed','Live Mode could not be enabled.'),true);return;}isDryRun=false;$('.ilsm-mode-badge').removeClass('is-dry-run').addClass('is-live').text(ilsmT('liveModeBadge','Live Mode: links are written to content'));message(ilsmT('liveModeStarting','Live Mode enabled. Starting selected links…'),false);startBulkInsertion(requested);}).fail(function(xhr,status){message(responseError(xhr,status==='timeout'?ilsmT('liveModeTimeout','Enabling Live Mode timed out.'):ilsmT('liveModeEnableFailed','Live Mode could not be enabled.')),true);});return;}startBulkInsertion(requested);});
    $('#ilsm-confirm-insert').on('change',function(){$('#ilsm-confirm-insert-button').prop('disabled',!this.checked);});
    function prependHistory(history){if(history){loadHistory(1,false);}}
    $(document).on('click','.ilsm-history-tab',function(){historyStatus=String($(this).data('history-status')||'all');$('.ilsm-history-tab').removeClass('is-active').attr('aria-selected','false');$(this).addClass('is-active').attr('aria-selected','true');$('#ilsm-history-load-more').attr('data-history-page','1');loadHistory(1,false);});
    $('#ilsm-history-load-more').on('click',function(){var next=parseInt($(this).attr('data-history-page')||1,10)+1;loadHistory(next,true);});
    $('#ilsm-confirm-insert-button').on('click',function(){var queue=pendingIds.slice(),total=queue.length,processed=0,results=[];$(':button','#ilsm-insert-modal').prop('disabled',true);$('#ilsm-insert-preview').prepend('<p class="ilsm-live-progress" aria-live="polite"><strong>Processing 0 / '+total+'…</strong></p>');function next(){if(!queue.length){$('#ilsm-insert-preview').html(resultHtml(results));$('#ilsm-confirm-insert').hide();$('#ilsm-confirm-insert-button').hide();$('[data-ilsm-close]').prop('disabled',false).text(ilsmT('close','Close'));$('.ilsm-modal-close').prop('disabled',false);var insertedCount=results.filter(function(r){return r.status==='inserted';}).length,failed=results.filter(function(r){return r.status==='failed';}).length,skippedCount=results.filter(function(r){return r.status==='skipped';}).length;if(insertedCount){$('#ilsm-insert-modal-title').text(ilsmT('internalLinkInsertedTitle','Internal link inserted successfully'));$('.ilsm-modal-titlebar p').text(ilsmT('internalLinkInsertedHelp','The verified internal link was added to the source content and confirmed after saving.'));$('#ilsm-insert-modal').attr('data-result-state','success');}else if(failed){$('#ilsm-insert-modal-title').text(ilsmT('internalLinkNotInsertedTitle','Internal link not inserted'));$('.ilsm-modal-titlebar p').text(ilsmT('internalLinkNotInsertedHelp','No link was written. Review the reason and preview the opportunity again if needed.'));$('#ilsm-insert-modal').attr('data-result-state','error');}else if(isDryRun){$('#ilsm-insert-modal-title').text(ilsmT('previewCompleted','Preview completed'));$('.ilsm-modal-titlebar p').text(ilsmT('previewCompletedHelp','The selected opportunity was verified safely. No content has been changed.'));$('#ilsm-insert-modal').attr('data-result-state','success');}else{$('#ilsm-insert-modal-title').text(ilsmT('insertionSkipped','Insertion skipped'));$('.ilsm-modal-titlebar p').text(ilsmT('noContentChanged','No content was changed.'));$('#ilsm-insert-modal').attr('data-result-state','warning');}message(ilsmFmtN('finishedCounts','Finished: %1$s inserted · %2$s skipped · %3$s failed',[insertedCount,skippedCount,failed]),!!failed);return;}var id=queue.shift(),preview=previewById[id]||{};request('ilsm_opportunity_insert',{id:id,snapshot_token:preview.snapshot_token||''},45000).done(function(r){var data=r&&r.data?r.data:{},status=r&&r.success?(data.status||'inserted'):'failed',row=rowFor(id);if(status==='inserted'){row.find('.ilsm-insertion-state').text(ilsmT('inserted','Inserted'));row.find('.ilsm-outgoing-count,.ilsm-incoming-count').each(function(){$(this).text(parseInt($(this).text()||0,10)+1);});row.find('.ilsm-opportunity-check').prop('checked',false).prop('disabled',true);prependHistory(data.history);finalizeInsertedRow(id);}else if(status==='dry_run_passed'){row.find('.ilsm-insertion-state').text(ilsmT('previewPassed','Preview passed'));}else if(status==='skipped'){row.find('.ilsm-insertion-state').text(ilsmT('skipped','Skipped'));}else{row.find('.ilsm-insertion-state').text(data.code||'Failed');}results.push({id:id,status:status,label:status==='inserted'?ilsmT('linkNowLive','The link is now live'):(status==='dry_run_passed'?ilsmT('previewPassed','Preview passed'):(status==='skipped'?ilsmT('notInserted','Not inserted'):ilsmT('insertionFailed','Insertion failed'))),message:data.message||ilsmT('noDetails','No details returned.'),code:data.code||'',source_title:data.source_title||preview.source_title,target_title:data.target_title||preview.target_title,anchor:data.anchor||preview.anchor,source_edit_url:data.source_edit_url||preview.source_edit_url,source_view_url:data.source_view_url||preview.source_view_url,destination_url:data.destination_url||preview.destination_url});processed++;$('.ilsm-live-progress strong').text(ilsmFmtN('processingProgress','Processing %1$s / %2$s…',[processed,total]));next();}).fail(function(xhr,status){var msg=responseError(xhr,status==='timeout'?ilsmT('insertionTimeout','The insertion request timed out. WordPress may still have saved the content; open the source page before retrying.'):ilsmT('insertionRequestFailed','The insertion request failed.'));rowFor(id).find('.ilsm-insertion-state').text(ilsmT('failedShort','Failed'));results.push({id:id,status:'failed',label:ilsmT('insertionFailed','Insertion failed'),message:msg,code:(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.code)||status,source_title:preview.source_title,target_title:preview.target_title,anchor:preview.anchor,source_edit_url:preview.source_edit_url,source_view_url:preview.source_view_url,destination_url:preview.destination_url});processed++;$('.ilsm-live-progress strong').text(ilsmFmtN('processingProgress','Processing %1$s / %2$s…',[processed,total]));next();});}next();});
    $(document).on('click','.ilsm-undo-opportunity',function(){var $row=$(this).closest('tr'),history=parseInt($(this).data('history-id')||$row.attr('data-history-id')||0,10);if(!history||!window.confirm(ilsmT('removeInsertedConfirm','Remove only the inserted link markup and preserve the anchor text?'))){return;}request('ilsm_opportunity_undo',{history_id:history}).done(function(r){if(r&&r.success){if($row.closest('.ilsm-insertion-history-panel').length){loadHistory(1,false);}else{$row.find('.ilsm-insertion-state').text(ilsmT('undone','Undone'));$row.attr('data-history-id','');}}else{message((r.data&&r.data.message)||ilsmT('undoFailed','Undo failed.'),true);}});});
    $(document).on('click','.ilsm-enable-live-from-result',function(){
        var $button=$(this),original=$button.text();
        if(!ILSM.canManageSettings){window.location.href=ILSM.settingsUrl||'admin.php?page=ilsm-settings#ilsm-safe-link-insertion';return;}
        if(!window.confirm(ilsmT('enableLiveConfirm','Enable Live Mode? Future approved insertions will write links into content. Existing Preview results will not be inserted automatically.'))){return;}
        $button.prop('disabled',true).text(ilsmT('enabling','Enabling…'));
        request('ilsm_enable_live_insertion',{},15000).done(function(r){
            if(!r||!r.success){message((r&&r.data&&r.data.message)||ilsmT('liveModeEnableFailed','Live Mode could not be enabled.'),true);$button.prop('disabled',false).text(original);return;}
            isDryRun=false;
            $('.ilsm-mode-badge').removeClass('is-dry-run').addClass('is-live').text(ilsmT('liveModeBadge','Live Mode: links are written to content'));
            $('.ilsm-preview-mode-help').replaceWith('<aside class="ilsm-live-mode-enabled" role="status">'+esc(ilsmT('liveModeResultNotice','Live Mode enabled. Close this dialog, select the opportunities again, and run Insert Selected Links to write them into content.'))+'</aside>');
            $('#ilsm-insert-modal-title').text(ilsmT('liveModeEnabled','Live Mode enabled'));
            $('.ilsm-modal-titlebar p').text(ilsmT('liveModeEnabledHelp','Preview is now disabled. Approved links will be written and verified during the next insertion run.'));
            $('#ilsm-insert-modal').attr('data-result-state','success');
            message(ilsmT('liveModeRunAgain','Live Mode enabled. Run Insert Selected Links again to write approved links.'),false);
        }).fail(function(xhr,status){message(responseError(xhr,status==='timeout'?ilsmT('liveModeTimeout','Enabling Live Mode timed out.'):ilsmT('liveModeEnableFailed','Live Mode could not be enabled.')),true);$button.prop('disabled',false).text(original);});
    });
    $(document).on('click','.ilsm-modal-close,[data-ilsm-close]',function(){modal(false);});
    $(document).on('keydown',function(e){if(e.key==='Escape'&&!$('#ilsm-insert-modal').prop('hidden')){modal(false);}});
});

// Accessible Visual Map tabs and real architecture views.
jQuery(function($){
    'use strict';
    function ilsmT(key, fallback){ return ilsmAdminT(key, fallback); }
    function ilsmFmt(key, fallback, value){ return ilsmT(key, fallback).replace('%s', String(value)); }
    function ilsmFmtN(key, fallback, values){ var out=ilsmT(key,fallback); (values||[]).forEach(function(value,index){ out=out.replace('%'+(index+1)+'$s',String(value)); }); return out; }
    var architectureCache={};

    $('.ilsm-map-tabs').on('keydown','[role="tab"]',function(event){
        var tabs=$('.ilsm-map-tabs [role="tab"]'),index=tabs.index(this);
        if(event.key==='ArrowRight'){event.preventDefault();tabs.eq((index+1)%tabs.length).trigger('focus').trigger('click');}
        if(event.key==='ArrowLeft'){event.preventDefault();tabs.eq((index-1+tabs.length)%tabs.length).trigger('focus').trigger('click');}
        if(event.key==='Home'){event.preventDefault();tabs.eq(0).trigger('focus').trigger('click');}
        if(event.key==='End'){event.preventDefault();tabs.eq(tabs.length-1).trigger('focus').trigger('click');}
    });
    $('.ilsm-map-tabs').on('click','[role="tab"]',function(event){
        event.preventDefault();
        var slug=String($(this).data('map-tab')||'link-map');
        $('.ilsm-map-tab').removeClass('is-active').attr('aria-selected','false');
        $(this).addClass('is-active').attr('aria-selected','true');
        $('.ilsm-map-tabpanel').removeClass('is-active').prop('hidden',true);
        $('#ilsm-panel-'+slug).addClass('is-active').prop('hidden',false);
        var url=new URL(window.location.href);url.searchParams.set('view',slug);window.history.replaceState({},'',url.toString());
        var panel=$('#ilsm-panel-'+slug);
        if(panel.is('[data-architecture-mode]')){
            if(!panel.data('loaded')){loadArchitecture(panel);}
            else{scheduleArchitectureFit(panel);}
        }
    });

    $('.ilsm-segmented').on('click','button',function(){
        var button=$(this),panel=button.closest('[data-architecture-mode]');
        if(button.hasClass('is-active')){return;}
        button.siblings().removeClass('is-active').attr('aria-pressed','false');
        button.addClass('is-active').attr('aria-pressed','true');
        if(panel.length){
            loadArchitecture(panel,true);
        }
    });
    $('.ilsm-layout-switcher').on('click','button',function(){
        var button=$(this),panel=button.closest('[data-architecture-mode]');
        button.siblings().removeClass('is-active').attr('aria-pressed','false');
        button.addClass('is-active').attr('aria-pressed','true');
        if(panel.data('graph')){renderArchitecture(panel,panel.data('graph'));}
        else{loadArchitecture(panel);}
    });
    $('.ilsm-load-architecture').on('click',function(){loadArchitecture($(this).closest('[data-architecture-mode]'),true);});
    $('.ilsm-architecture-root,.ilsm-type-filter input,.ilsm-architecture-status,.ilsm-min-in,.ilsm-min-out').on('change',function(){loadArchitecture($(this).closest('[data-architecture-mode]'),true);});
    $('.ilsm-architecture-search').on('input',function(){applyArchitectureClientFilter($(this).closest('[data-architecture-mode]'));});
    $(document).on('click','.ilsm-architecture-filter-chips button',function(){
        var panel=$(this).closest('[data-architecture-mode]');
        $(this).siblings().removeClass('is-active').attr('aria-pressed','false');
        $(this).addClass('is-active').attr('aria-pressed','true');
        applyArchitectureClientFilter(panel);
    });
    function applyArchitectureClientFilter(panel){
        var q=String(panel.find('.ilsm-architecture-search').val()||'').toLowerCase();
        var filter=String(panel.find('.ilsm-architecture-filter-chips .is-active').data('arch-filter')||'all');
        panel.find('[data-node-id]').each(function(){
            var el=$(this),title=String(el.data('node-title')||'').toLowerCase(),hit=!q||title.indexOf(q)!==-1,filterHit=true;
            if(filter==='orphan'){filterHit=Number(el.attr('data-orphan')||0)===1;}
            else if(filter==='no-outgoing'){filterHit=Number(el.attr('data-outgoing')||0)===0;}
            else if(filter==='deep'){filterHit=Number(el.attr('data-depth')||0)>=3;}
            else if(filter==='redirect'){filterHit=Number(el.attr('data-redirect')||0)===1;}
            else if(filter==='broken'){filterHit=Number(el.attr('data-broken')||0)===1;}
            el.toggleClass('is-dimmed',!(hit&&filterHit)).toggleClass('is-highlighted',!!q&&hit&&filterHit);
        });
    }
    function nodeDataAttrs(n){return ' data-depth="'+Number(n.depth||0)+'" data-outgoing="'+Number(n.outgoing_count||0)+'" data-orphan="'+(n.is_orphan?1:0)+'" data-redirect="'+(n.is_redirect?1:0)+'" data-broken="'+(n.is_broken?1:0)+'"';}

    function requestArchitecture(panel){
        var types=panel.find('.ilsm-type-filter input:checked').map(function(){return this.value;}).get();
        var mode=String(panel.data('architecture-mode')||'page');
        return {
            action:'ilsm_architecture_data',nonce:ILSM.nonce,
            mode:mode,
            root_id:mode==='site'?0:parseInt(panel.find('.ilsm-architecture-root').val()||0,10),
            max_depth:mode==='site'?0:parseInt(panel.find('.ilsm-segmented .is-active').data('depth')||0,10),
            post_types:types,
            status:String(panel.find('.ilsm-architecture-status').val()||'all'),
            min_in:parseInt(panel.find('.ilsm-min-in').val()||0,10),
            min_out:parseInt(panel.find('.ilsm-min-out').val()||0,10)
        };
    }
    function loadArchitecture(panel,force){
        var args=requestArchitecture(panel),key=JSON.stringify(args);
        panel.find('.ilsm-architecture-loading').prop('hidden',false);
        panel.find('.ilsm-architecture-canvas').attr('aria-busy','true');
        if(!force&&architectureCache[key]){finishArchitecture(panel,architectureCache[key]);return;}
        $.ajax({url:ILSM.ajax,method:'POST',dataType:'json',timeout:60000,data:args}).done(function(r){
            if(!r||!r.success){architectureError(panel,(r&&r.data&&r.data.message)||'Architecture data could not be loaded.');return;}
            architectureCache[key]=r.data;finishArchitecture(panel,r.data);
        }).fail(function(xhr){architectureError(panel,(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message)||'Architecture request failed.');});
    }
    function finishArchitecture(panel,data){
        panel.data('loaded',true).data('graph',data);
        panel.find('.ilsm-architecture-loading').prop('hidden',true);
        panel.find('.ilsm-architecture-canvas').attr('aria-busy','false');
        renderSummary(panel,data.meta&&data.meta.totals?data.meta.totals:{});
        var limitedNotice=panel.find('.ilsm-architecture-limited-notice');
        if(!limitedNotice.length){limitedNotice=$('<div class="notice notice-warning inline ilsm-architecture-limited-notice" hidden><p></p></div>');panel.find('.ilsm-architecture-summary').after(limitedNotice);}
        if(data.meta&&data.meta.limited){limitedNotice.find('p').text(ilsmT('visualizationLimited','This graph is limited for browser performance. Totals describe the full scanned dataset; graph metrics describe only the visualized subset.'));limitedNotice.prop('hidden',false);}else{limitedNotice.prop('hidden',true);}
        renderArchitecture(panel,data);
    }
    function architectureError(panel,message){
        panel.find('.ilsm-architecture-loading').prop('hidden',true);
        panel.find('.ilsm-architecture-canvas').attr('aria-busy','false').html('<div class="ilsm-architecture-error"><i class="fa fa-exclamation-triangle"></i><strong>'+escapeHtml(message)+'</strong></div>');
    }
    function escapeHtml(value){return $('<div>').text(value==null?'':String(value)).html();}
    function renderSummary(panel,m){
        panel.find('.ilsm-architecture-summary').html(kpi('fa-files-o',Number(m.total_pages||0).toLocaleString(),ilsmT('totalPages','Total pages'),'is-purple')+kpi('fa-sitemap',Number(m.total_levels||0).toLocaleString(),ilsmT('levels','Levels'),'is-blue')+kpi('fa-exclamation-triangle',Number(m.orphan_pages||0).toLocaleString(),ilsmT('orphanPages','Orphan pages'),'is-red')+kpi('fa-link',Number(m.total_internal_links||0).toLocaleString(),ilsmT('internalLinks','Internal links'),'is-green')+kpi('fa-chain-broken',Number(m.broken_internal_links||0).toLocaleString(),ilsmT('brokenLinks','Broken links'),'is-red')+kpi('fa-shield',Number(m.architecture_score||0)+'/100',ilsmT('architectureHealth','Architecture health'),'is-health'));
        panel.find('.ilsm-architecture-metrics').html(metric(ilsmT('totalPages','Total pages'),m.total_pages)+metric(ilsmT('totalInternalLinks','Total internal links'),m.total_internal_links)+metric(ilsmT('totalLevels','Total levels'),m.total_levels)+metric(ilsmT('averageDepth','Average depth'),m.average_depth)+metric(ilsmT('maximumDepth','Maximum depth'),m.maximum_depth)+metric(ilsmT('orphanPages','Orphan pages'),m.orphan_pages,true)+metric('Broken internal links',m.broken_internal_links,true)+metric(ilsmT('pagesWithoutChildren','Pages without children'),m.pages_without_children)+metric(ilsmT('pagesWithoutOutgoing','Pages without outgoing'),m.pages_without_outgoing)+metric(ilsmT('architectureHealth','Architecture health'),String(m.architecture_score||0)+'/100'));
    }
    function metric(label,value,alert){return '<div class="ilsm-architecture-metric"><span>'+escapeHtml(label)+'</span><strong class="'+(alert&&Number(value)>0?'is-alert':'')+'">'+escapeHtml(value==null?0:value)+'</strong></div>';}
    function kpi(icon,value,label,tone){return '<div class="ilsm-arch-kpi '+escapeHtml(tone||'')+'"><span class="ilsm-arch-kpi-icon"><i class="fa '+escapeHtml(icon)+'" aria-hidden="true"></i></span><span class="ilsm-arch-kpi-copy"><strong>'+escapeHtml(value)+'</strong><small>'+escapeHtml(label)+'</small></span></div>';}
    function renderArchitecture(panel,data){
        if(!data||!data.nodes){return;}
        var layout=String(panel.find('.ilsm-layout-switcher .is-active').data('layout')||'tree');
        if(layout==='list'){renderList(panel,data);return;}
        if(layout==='grid'){renderGrid(panel,data);return;}
        renderSvg(panel,data,layout);
    }
    function nodeClass(node){return node.is_orphan?'is-orphan':'level-'+Math.min(3,parseInt(node.depth||0,10));}
    function renderList(panel,data){
        var html='<div class="ilsm-architecture-list" role="table"><div class="is-head" role="row"><span>Page</span><span>Type</span><span>Depth</span><span>'+escapeHtml(ilsmT('incoming','Incoming'))+'</span><span>'+escapeHtml(ilsmT('outgoing','Outgoing'))+'</span><span>Children</span><span>Score</span></div>';
        data.nodes.slice().sort(function(a,b){return a.depth-b.depth||a.title.localeCompare(b.title);}).forEach(function(n){html+='<button type="button" class="ilsm-architecture-list-row '+nodeClass(n)+'" role="row" data-node-id="'+n.id+'" data-node-title="'+escapeHtml(n.title)+'"'+nodeDataAttrs(n)+'><span>'+escapeHtml(n.title)+'<small>'+escapeHtml(n.path||'')+'</small></span><span>'+escapeHtml(n.post_type)+'</span><span>'+n.depth+'</span><span>'+n.incoming_count+'</span><span>'+n.outgoing_count+'</span><span>'+n.child_count+'</span><span>'+n.seo_score+'</span></button>';});
        panel.find('.ilsm-architecture-canvas').removeClass('is-grid-layout').addClass('is-list-layout').html(html+'</div>');disableArchitectureViewport(panel);bindNodeClicks(panel,data);applyArchitectureClientFilter(panel);
    }
    function renderGrid(panel,data){
        var html='<div class="ilsm-architecture-card-grid">';data.nodes.slice().sort(function(a,b){return a.depth-b.depth||b.authority_score-a.authority_score;}).forEach(function(n){html+='<button type="button" class="ilsm-architecture-card '+nodeClass(n)+'" data-node-id="'+n.id+'" data-node-title="'+escapeHtml(n.title)+'"'+nodeDataAttrs(n)+'><strong>'+escapeHtml(n.title)+'</strong><small>'+escapeHtml(n.path||'')+'</small><span>'+escapeHtml(n.post_type)+' · Level '+n.depth+'</span><em>'+n.incoming_count+' in · '+n.outgoing_count+' out · '+n.child_count+' children</em></button>';});panel.find('.ilsm-architecture-canvas').removeClass('is-list-layout').addClass('is-grid-layout').html(html+'</div>');disableArchitectureViewport(panel);bindNodeClicks(panel,data);applyArchitectureClientFilter(panel);
    }
    function renderSvg(panel,data,layout){
        var nodes=data.nodes,edges=data.edges||[],positions={},byDepth={},groups={},isCardLayout=layout==='tree'||layout==='horizontal';
        nodes.forEach(function(n){var d=Number(n.depth||0);byDepth[d]=byDepth[d]||[];byDepth[d].push(n);});
        var depthKeys=Object.keys(byDepth),maxDepth=depthKeys.length?Math.max.apply(null,depthKeys.map(Number)):0;
        var widest=1;depthKeys.forEach(function(d){widest=Math.max(widest,byDepth[d].length);});
        var width=isCardLayout?(layout==='horizontal'?Math.max(1450,(maxDepth+1)*285):Math.max(1450,widest*220+260)):Math.max(1200,widest*150+180);
        var height=isCardLayout?(layout==='horizontal'?Math.max(760,widest*100+160):Math.max(760,(maxDepth+1)*180+150)):Math.max(680,(maxDepth+1)*175+120);

        if(layout==='radial'||layout==='force'||layout==='pack'){
            var cx=width/2,cy=height/2,ringStep=Math.max(105,Math.min(width,height)/(2*Math.max(2,maxDepth+1)));
            nodes.forEach(function(n){var d=Number(n.depth||0);groups[d]=groups[d]||[];groups[d].push(n);});
            Object.keys(groups).forEach(function(d){var arr=groups[d],radius=Number(d)===0?0:ringStep*Number(d);arr.forEach(function(n,i){var angle=(Math.PI*2*i/Math.max(1,arr.length))-Math.PI/2;positions[n.id]={x:cx+Math.cos(angle)*radius,y:cy+Math.sin(angle)*radius};});});
        }else{
            depthKeys.forEach(function(d){var arr=byDepth[d];arr.sort(function(a,b){return Number(b.authority_score||0)-Number(a.authority_score||0)||String(a.title).localeCompare(String(b.title));});arr.forEach(function(n,i){if(layout==='horizontal'){positions[n.id]={x:125+Number(d)*275,y:95+(i+1)*(height-190)/(arr.length+1)};}else{positions[n.id]={x:120+(i+1)*(width-240)/(arr.length+1),y:100+Number(d)*175};}});});
        }
        var svg='<div class="ilsm-architecture-viewport"><svg class="ilsm-architecture-svg" width="'+width+'" height="'+height+'" viewBox="0 0 '+width+' '+height+'" role="img" aria-label="Architecture graph"><g class="ilsm-architecture-content">';
        edges.forEach(function(e){var a=positions[e.source],b=positions[e.target];if(!a||!b)return;var x1=a.x,y1=a.y,x2=b.x,y2=b.y;if(isCardLayout){if(layout==='horizontal'){x1+=95;x2-=95;}else{y1+=34;y2-=34;}}svg+='<line class="ilsm-architecture-edge '+escapeHtml(e.relationship_type)+'" x1="'+x1+'" y1="'+y1+'" x2="'+x2+'" y2="'+y2+'"><title>'+escapeHtml(e.relationship_type)+'</title></line>';});
        nodes.forEach(function(n){var pos=positions[n.id];if(!pos)return;var attrs=nodeDataAttrs(n);if(isCardLayout){var title=shorten(n.title,27),path=shorten(n.path||'',30),status=n.is_orphan?'Orphan':'Level '+Number(n.depth||0);svg+='<g class="ilsm-architecture-node ilsm-architecture-node-card '+nodeClass(n)+'" tabindex="0" role="button" aria-label="'+escapeHtml(n.title)+', level '+Number(n.depth||0)+'" data-node-id="'+n.id+'" data-node-title="'+escapeHtml(n.title)+'"'+attrs+' transform="translate('+pos.x+' '+pos.y+')"><rect x="-95" y="-34" width="190" height="68" rx="9"></rect><rect class="ilsm-node-accent" x="-95" y="-34" width="5" height="68" rx="3"></rect><text class="ilsm-card-node-title" x="-80" y="-10">'+escapeHtml(title)+'</text><text class="ilsm-card-node-path" x="-80" y="10">'+escapeHtml(path)+'</text><text class="ilsm-card-node-meta" x="-80" y="26">'+escapeHtml(status)+' · '+Number(n.incoming_count||0)+' in · '+Number(n.outgoing_count||0)+' out</text><title>'+escapeHtml(n.title)+' · '+n.incoming_count+' incoming · '+n.outgoing_count+' outgoing</title></g>';}
            else{var radius=Number(n.depth||0)===0?40:Math.max(22,Math.min(32,22+Number(n.authority_score||0)/12));var label=shorten(n.title,22);svg+='<g class="ilsm-architecture-node '+nodeClass(n)+'" tabindex="0" role="button" aria-label="'+escapeHtml(n.title)+', level '+Number(n.depth||0)+'" data-node-id="'+n.id+'" data-node-title="'+escapeHtml(n.title)+'"'+attrs+' transform="translate('+pos.x+' '+pos.y+')"><circle r="'+radius+'"></circle><text class="ilsm-architecture-node-label" y="'+(radius+17)+'" text-anchor="middle">'+escapeHtml(label)+'</text><title>'+escapeHtml(n.title)+' · '+n.incoming_count+' incoming · '+n.outgoing_count+' outgoing</title></g>';}});
        panel.find('.ilsm-architecture-canvas').removeClass('is-list-layout is-grid-layout').html(svg+'</g></svg></div>');
        initArchitectureViewport(panel,{width:width,height:height,fit:true});
        bindNodeClicks(panel,data);applyArchitectureClientFilter(panel);
    }

    function architectureViewportState(panel){
        var state=panel.data('architectureViewport');
        if(!state){state={scale:1,x:0,y:0,min:.03,max:4,width:1200,height:680,dragging:false};panel.data('architectureViewport',state);}
        return state;
    }
    function architectureCanvas(panel){return panel.find('.ilsm-architecture-canvas').get(0);}
    function applyArchitectureViewport(panel){
        var state=architectureViewportState(panel),viewport=panel.find('.ilsm-architecture-viewport').get(0);
        if(!viewport)return;
        viewport.style.transform='translate('+state.x+'px,'+state.y+'px) scale('+state.scale+')';
        panel.find('.ilsm-architecture-node-label').each(function(){
            var label=this,node=this.parentNode,circle=node?node.querySelector('circle'):null,radius=circle?parseFloat(circle.getAttribute('r')||24):24;
            label.setAttribute('font-size',String(12/state.scale));
            label.setAttribute('y',String(radius+(17/state.scale)));
            label.style.strokeWidth=String(4/state.scale)+'px';
        });
        panel.find('.ilsm-arch-zoom-value').text(Math.round(state.scale*100)+'%');
    }
    function architectureContentBounds(panel){
        var content=panel.find('.ilsm-architecture-content').get(0);
        if(!content||typeof content.getBBox!=='function')return null;
        try{
            var box=content.getBBox();
            if(box&&isFinite(box.x)&&isFinite(box.y)&&box.width>0&&box.height>0){
                return {x:box.x,y:box.y,width:box.width,height:box.height};
            }
        }catch(ignore){}
        return null;
    }
    function fitArchitectureViewport(panel){
        var state=architectureViewportState(panel),canvas=architectureCanvas(panel);if(!canvas||!panel.is(':visible'))return false;
        var cw=Math.round(canvas.clientWidth),ch=Math.round(canvas.clientHeight),padding=56;
        if(cw<80||ch<80)return false;
        var bounds=architectureContentBounds(panel)||{x:0,y:0,width:state.width,height:state.height};
        var availableWidth=Math.max(1,cw-padding*2),availableHeight=Math.max(1,ch-padding*2);
        var scale=Math.min(availableWidth/Math.max(1,bounds.width),availableHeight/Math.max(1,bounds.height));
        state.scale=Math.max(state.min,Math.min(state.max,scale));
        state.x=Math.round((cw-(bounds.width*state.scale))/2-(bounds.x*state.scale));
        state.y=Math.round((ch-(bounds.height*state.scale))/2-(bounds.y*state.scale));
        applyArchitectureViewport(panel);
        return true;
    }
    function scheduleArchitectureFit(panel){
        if(!panel||!panel.length)return;
        var token=Number(panel.data('architectureFitToken')||0)+1;
        panel.data('architectureFitToken',token);
        function runFit(){
            if(Number(panel.data('architectureFitToken')||0)!==token)return;
            fitArchitectureViewport(panel);
        }
        window.requestAnimationFrame(function(){
            window.requestAnimationFrame(function(){
                runFit();
                [60,180,420,800,1200].forEach(function(delay){window.setTimeout(runFit,delay);});
            });
        });
    }
    function resetArchitectureViewport(panel){
        var state=architectureViewportState(panel),canvas=architectureCanvas(panel);if(!canvas)return;
        state.scale=1;state.x=Math.max(0,(canvas.clientWidth-state.width)/2);state.y=20;applyArchitectureViewport(panel);
    }
    function zoomArchitectureViewport(panel,factor,originX,originY){
        var state=architectureViewportState(panel),canvas=architectureCanvas(panel);if(!canvas)return;
        var old=state.scale,next=Math.max(state.min,Math.min(state.max,old*factor));if(next===old)return;
        var rect=canvas.getBoundingClientRect(),ox=originX==null?rect.width/2:originX-rect.left,oy=originY==null?rect.height/2:originY-rect.top;
        state.x=ox-(ox-state.x)*(next/old);state.y=oy-(oy-state.y)*(next/old);state.scale=next;applyArchitectureViewport(panel);
    }
    function disableArchitectureViewport(panel){
        var canvas=architectureCanvas(panel);
        if(canvas){$(canvas).off('.ilsmViewport').removeClass('is-panning');}
        panel.removeData('architectureViewport').find('.ilsm-architecture-viewport-tools button').prop('disabled',true);
        panel.find('.ilsm-arch-zoom-value').text('100%');
    }
    function initArchitectureViewport(panel,dimensions){
        var canvas=architectureCanvas(panel),state=architectureViewportState(panel);if(!canvas)return;
        state.width=Number(dimensions.width||1200);state.height=Number(dimensions.height||680);state.dragging=false;
        panel.find('.ilsm-architecture-viewport-tools button').prop('disabled',false);
        var $canvas=$(canvas);
        $canvas.off('.ilsmViewport');
        $canvas.on('wheel.ilsmViewport',function(event){event.preventDefault();var oe=event.originalEvent;zoomArchitectureViewport(panel,oe.deltaY<0?1.12:.89,oe.clientX,oe.clientY);});
        $canvas.on('pointerdown.ilsmViewport',function(event){if($(event.target).closest('[data-node-id]').length)return;state.dragging=true;state.pointerId=event.originalEvent.pointerId;state.startX=event.originalEvent.clientX;state.startY=event.originalEvent.clientY;state.baseX=state.x;state.baseY=state.y;canvas.setPointerCapture&&canvas.setPointerCapture(state.pointerId);$canvas.addClass('is-panning');});
        $canvas.on('pointermove.ilsmViewport',function(event){if(!state.dragging)return;state.x=state.baseX+(event.originalEvent.clientX-state.startX);state.y=state.baseY+(event.originalEvent.clientY-state.startY);applyArchitectureViewport(panel);});
        $canvas.on('pointerup.ilsmViewport pointercancel.ilsmViewport',function(event){if(!state.dragging)return;state.dragging=false;$canvas.removeClass('is-panning');try{canvas.releasePointerCapture&&canvas.releasePointerCapture(event.originalEvent.pointerId);}catch(ignore){}});
        $canvas.on('keydown.ilsmViewport',function(event){if(event.key==='+'||event.key==='='){event.preventDefault();zoomArchitectureViewport(panel,1.15);}else if(event.key==='-'){event.preventDefault();zoomArchitectureViewport(panel,.87);}else if(event.key==='0'){event.preventDefault();fitArchitectureViewport(panel);}});
        // Put the graph in a safe visible position immediately, then fit it once
        // WordPress has finished calculating the admin layout. This prevents the
        // first render from appearing outside the viewport until Reset is clicked.
        resetArchitectureViewport(panel);
        if(dimensions.fit){scheduleArchitectureFit(panel);}else{applyArchitectureViewport(panel);}
        if(window.ResizeObserver&&!panel.data('architectureResizeObserver')){
            var observer=new ResizeObserver(function(){if(panel.is(':visible')&&panel.find('.ilsm-architecture-viewport').length){scheduleArchitectureFit(panel);}});
            observer.observe(canvas);panel.data('architectureResizeObserver',observer);
        }
    }
    $(document).on('click','.ilsm-arch-zoom-in',function(){zoomArchitectureViewport($(this).closest('[data-architecture-mode]'),1.18);});
    $(document).on('click','.ilsm-arch-zoom-out',function(){zoomArchitectureViewport($(this).closest('[data-architecture-mode]'),.84);});
    $(document).on('click','.ilsm-arch-fit',function(){scheduleArchitectureFit($(this).closest('[data-architecture-mode]'));});
    $(document).on('click','.ilsm-arch-reset',function(){var panel=$(this).closest('[data-architecture-mode]');resetArchitectureViewport(panel);scheduleArchitectureFit(panel);});
    $(window).on('resize.ilsmArchitecture',function(){window.clearTimeout(window.ilsmArchitectureResize);window.ilsmArchitectureResize=window.setTimeout(function(){$('.ilsm-map-tabpanel.is-active[data-architecture-mode]').each(function(){var panel=$(this);if(panel.find('.ilsm-architecture-viewport').length)scheduleArchitectureFit(panel);});},150);});

    function shorten(value,max){value=String(value||'');return value.length>max?value.slice(0,max-1)+'…':value;}
    function bindNodeClicks(panel,data){
        panel.find('[data-node-id]').off('click.ilsmArch keydown.ilsmArch').on('click.ilsmArch',function(){selectNode(panel,data,parseInt($(this).data('node-id'),10));}).on('keydown.ilsmArch',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();$(this).trigger('click');}});
    }
    function selectNode(panel,data,id){
        var node=(data.nodes||[]).find(function(n){return Number(n.id)===Number(id);});if(!node)return;
        panel.find('[data-node-id]').removeClass('is-selected');panel.find('[data-node-id="'+id+'"]').addClass('is-selected');
        var linkMap='admin.php?page=ilsm-visual-map&view=link-map&post_id='+encodeURIComponent(id),opps='admin.php?page=ilsm-link-opportunities&source_post_id='+encodeURIComponent(id),audit='admin.php?page=ilsm-health-audit&post_id='+encodeURIComponent(id);
        panel.find('.ilsm-architecture-details').html('<span class="ilsm-badge '+(node.is_orphan?'is-warning':'is-success')+'">'+(node.is_orphan?ilsmT('orphan','Orphan'):ilsmT('level','Level')+' '+node.depth)+'</span><h3>'+escapeHtml(node.title)+'</h3><p class="ilsm-muted">'+escapeHtml(node.path||node.url)+'</p>'+metric(ilsmT('postType','Post type'),node.post_type)+metric(ilsmT('incomingLinks','Incoming links'),node.incoming_count)+metric(ilsmT('outgoingLinks','Outgoing links'),node.outgoing_count)+metric(ilsmT('children','Children'),node.child_count)+metric(ilsmT('seoScore','SEO score'),node.seo_score+'/100')+'<p><small>'+ilsmT('relationship','Relationship')+': '+escapeHtml(String(node.relationship_type||'root').replace(/_/g,' '))+'</small></p><div class="ilsm-drawer-actions">'+(node.edit_url?'<a class="ilsm-btn ilsm-btn-primary" href="'+escapeHtml(node.edit_url)+'"><i class="fa fa-pencil"></i> '+ilsmT('editPage','Edit page')+'</a>':'')+'<a class="ilsm-btn" href="'+escapeHtml(node.url)+'" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> '+ilsmT('viewPage','View page')+'</a><a class="ilsm-btn" href="'+linkMap+'"><i class="fa fa-sitemap"></i> '+ilsmT('openLinkMap','Open in Link Map')+'</a><a class="ilsm-btn" href="'+opps+'"><i class="fa fa-link"></i> '+ilsmT('linkOpportunities','Link Opportunities')+'</a><a class="ilsm-btn" href="'+audit+'"><i class="fa fa-heartbeat"></i> '+ilsmT('healthAudit','Health Audit')+'</a></div>');
    }

    var current=$('.ilsm-map-tabpanel.is-active[data-architecture-mode]');if(current.length){loadArchitecture(current);}
});


jQuery(function($){
    var $modal = $('#ilsm-seo-analysis-modal');
    if (!$modal.length) { return; }
    var previousFocus = null;

    function esc(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }
    function closeSeoModal() {
        $modal.attr({'hidden':'hidden','aria-hidden':'true'});
        $('body').removeClass('ilsm-seo-modal-open');
        if (previousFocus && previousFocus.focus) { previousFocus.focus(); }
    }
    function statusIcon(status) {
        if (status === 'good') { return 'fa-check-circle'; }
        if (status === 'warning') { return 'fa-exclamation-triangle'; }
        if (status === 'na') { return 'fa-minus-circle'; }
        return 'fa-times-circle';
    }
    function scoreTone(score) {
        if (score >= 90) { return 'excellent'; }
        if (score >= 70) { return 'good'; }
        if (score >= 50) { return 'warning'; }
        return 'poor';
    }
    function scoreGauge(score, label) {
        if (score === null || typeof score === 'undefined' || isNaN(Number(score))) {
            return '<div class="ilsm-seo-big-score ilsm-score-gauge--unverified"><div class="ilsm-seo-score-ring" style="--ilsm-score:0%"><span><strong>—</strong><small>SEO</small></span></div><em>'+esc(label || 'Not analyzed')+'</em></div>';
        }
        var numeric = Math.max(0, Math.min(100, parseInt(score, 10) || 0));
        return '<div class="ilsm-seo-big-score ilsm-score-gauge--'+scoreTone(numeric)+'"><div class="ilsm-seo-score-ring" style="--ilsm-score:'+numeric+'%" role="img" aria-label="SEO score: '+numeric+' out of 100"><span><strong>'+numeric+'</strong><small>/100</small></span></div><em>'+esc(label || '')+'</em></div>';
    }
    function uniqueChecks(checks) {
        var seen = {};
        return checks.filter(function(check, index){
            var key = String(check && (check.id || check.key || check.label) || index);
            if (seen[key]) { return false; }
            seen[key] = true;
            return true;
        });
    }
    function renderAnalysis(data) {
        var checks = uniqueChecks(Array.isArray(data.checks) ? data.checks : []);
        var html = '<div class="ilsm-seo-modal-head"><div><span class="ilsm-seo-eyebrow">SEO analysis</span><h2 id="ilsm-seo-modal-title">'+esc(data.title || 'Page')+'</h2><p>On-page SEO is measured from rendered public HTML. Internal linking comes from the latest completed crawl and is reported separately.</p></div>'+scoreGauge(data.score, data.label)+'</div>';
        if (data.verified) {
            html += '<div class="ilsm-seo-source-note"><i class="fa fa-globe" aria-hidden="true"></i> Verified from rendered public HTML'+(data.source_url ? ': '+esc(data.source_url) : '')+'.</div>';
        } else {
            html += '<div class="ilsm-seo-source-note is-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Rendered HTML could not be verified. Unverified values are shown as N/A, not zero.</div>';
        }
        html += '<div class="ilsm-seo-checks">';
        checks.forEach(function(check){
            var warnings = Array.isArray(check.warnings) ? check.warnings : [];
            html += '<article class="ilsm-seo-check is-'+esc(check.status)+'"><i class="fa '+statusIcon(check.status)+'" aria-hidden="true"></i><div><div class="ilsm-seo-check-title"><strong>'+esc(check.label)+'</strong><span>'+(check.not_applicable ? 'N/A' : esc(check.points)+'/'+esc(check.max))+'</span></div><p>'+esc(check.detail)+'</p>';
            warnings.forEach(function(w){ html += '<div class="ilsm-seo-warning"><i class="fa fa-level-up" aria-hidden="true"></i>'+esc(w)+'</div>'; });
            html += '</div></article>';
        });
        html += '</div>';
        var h = data.headings || {};
        html += '<section class="ilsm-seo-outline"><h3>Heading structure</h3><div>';
        ['h1','h2','h3','h4','h5','h6'].forEach(function(level){
            var hv = (h[level] === null || typeof h[level] === 'undefined') ? '—' : h[level];
            html += '<span><b>'+level.toUpperCase()+'</b>'+esc(hv)+'</span>';
        });
        html += '</div></section>';
        var links = data.internal_links || {};
        html += '<section class="ilsm-seo-links"><div><h3>Internal linking</h3><p>Not mixed into the on-page SEO score.</p></div><div class="ilsm-seo-link-metrics"><span><b>'+esc(links.incoming || 0)+'</b>Incoming</span><span><b>'+esc(links.outgoing || 0)+'</b>Outgoing</span><span><b>'+esc(links.broken || 0)+'</b>Broken</span><span><b>'+esc(links.weak || 0)+'</b>Weak anchors</span></div>'+(links.orphan ? '<div class="ilsm-seo-orphan"><i class="fa fa-unlink" aria-hidden="true"></i><strong>Orphan page</strong><span>No discovered incoming internal links in the latest completed scan.</span></div>' : '')+'</section>';
        var external = data.external_links || {};
        html += '<section class="ilsm-seo-links"><div><h3>External links</h3><p>Reported separately and not mixed into the on-page SEO score.</p></div><div class="ilsm-seo-link-metrics"><span><b>'+esc(external.total || 0)+'</b>Outgoing external</span><span><b>'+esc(external.nofollow || 0)+'</b>Nofollow</span><span><b>'+esc(external.broken || 0)+'</b>Broken</span><span><b>'+esc(external.redirects || 0)+'</b>Redirects</span></div></section>';
        return html;
    }

    $(document).on('click', '.ilsm-seo-score-trigger', function(){
        var postId = parseInt($(this).data('post-id'), 10) || 0;
        if (!postId || !window.ILSM || !$modal.length) { return; }
        previousFocus = this;
        $modal.removeAttr('hidden').attr('aria-hidden','false');
        $('body').addClass('ilsm-seo-modal-open');
        $('#ilsm-seo-modal-content').html('<div class="ilsm-seo-loading"><i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i> '+esc(ilsmT('analyzingRenderedHtml','Analyzing rendered public HTML…'))+'</div>');
        window.setTimeout(function(){ $modal.find('.ilsm-seo-modal-close').trigger('focus'); }, 0);
        var requestFinished=false;
        var request=$.ajax({
            url: ILSM.ajax,
            method: 'POST',
            timeout: 12000,
            data: {action:'ilsm_page_seo_analysis', nonce:ILSM.nonce, post_id:postId}
        })
            .done(function(response){
                requestFinished=true;
                if (response && response.success && response.data) {
                    try {
                        $('#ilsm-seo-modal-content').html(renderAnalysis(response.data));
                    } catch (error) {
                        $('#ilsm-seo-modal-content').html('<div class="ilsm-seo-error"><i class="fa fa-exclamation-triangle"></i> '+esc(ilsmT('seoAnalysisRenderFailed','The SEO breakdown could not be displayed. Reload this page and try again.'))+'</div>');
                    }
                } else {
                    $('#ilsm-seo-modal-content').html('<div class="ilsm-seo-error"><i class="fa fa-exclamation-triangle"></i> '+esc(response && response.data && response.data.message ? response.data.message : ilsmT('seoAnalysisFailed','SEO analysis failed.'))+'</div>');
                }
            })
            .fail(function(xhr, status){
                requestFinished=true;
                var message=status==='timeout'?ilsmT('seoAnalysisTimeout','SEO analysis timed out. The completed scan score remains unchanged; try again after page caching is available.'):ilsmT('seoAnalysisRequestFailed','SEO analysis request failed.');
                if(xhr&&xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message){message=xhr.responseJSON.data.message;}
                $('#ilsm-seo-modal-content').html('<div class="ilsm-seo-error"><i class="fa fa-exclamation-triangle"></i> '+esc(message)+'</div>');
            });
        window.setTimeout(function(){
            if(requestFinished){return;}
            requestFinished=true;
            request.abort();
            $('#ilsm-seo-modal-content').html('<div class="ilsm-seo-error"><i class="fa fa-exclamation-triangle"></i> '+esc(ilsmT('seoAnalysisTimeout','SEO analysis timed out. Run a fresh scan to rebuild the saved rendered-HTML breakdown.'))+'</div>');
        },13000);
    });
    $(document).on('click', '[data-ilsm-seo-close]', closeSeoModal);
    $(document).on('keydown', function(event){
        if ($modal.is('[hidden]')) { return; }
        if (event.key === 'Escape') { closeSeoModal(); return; }
        if (event.key !== 'Tab') { return; }
        var focusable = $modal.find('button:visible, a[href]:visible, input:visible, select:visible, textarea:visible, [tabindex]:not([tabindex="-1"]):visible').toArray();
        if (!focusable.length) { return; }
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
});

/* Same-site, read-only On-Page SEO audit. */
jQuery(function($){
    'use strict';
    var form=$('#ilsm-onpage-form');
    if(!form.length){return;}
    var status=$('#ilsm-onpage-status'),results=$('#ilsm-onpage-results');
    function escapeValue(value){return $('<div>').text(value==null?'':String(value)).html();}
    function tone(score){return score>=85?'excellent':(score>=70?'good':(score>=50?'warning':'poor'));}
    function icon(state){return state==='pass'?'fa-check-circle':(state==='warning'?'fa-exclamation-triangle':(state==='fail'?'fa-times-circle':'fa-info-circle'));}
    function issueCount(checks){return checks.filter(function(check){return check.status==='fail'||check.status==='warning';}).length;}
    function openHashTarget(){
        var id=(window.location.hash||'').replace(/^#/,'');if(!/^ilsm-audit-check-\d+$/.test(id)){return;}
        var target=document.getElementById(id);if(!target){return;}var group=target.closest('details');if(group){group.open=true;}window.setTimeout(function(){target.scrollIntoView({behavior:'smooth',block:'center'});target.setAttribute('tabindex','-1');target.focus({preventScroll:true});},60);
    }
    function render(data){
        var categories={technical:'Technical & indexability',metadata:'Search appearance',content:'Content & media',keyphrase:'Focus keyphrase',structured:'Structured data',crawlers:'Search & AI crawlers'};
        var grouped={},checkIndex=0;(data.checks||[]).forEach(function(check){check._auditId='ilsm-audit-check-'+checkIndex++;(grouped[check.category]||(grouped[check.category]=[])).push(check);});
        var score=parseInt(data.score,10)||0,scoreLabel=score>=85?'Strong':(score>=70?'Good foundation':(score>=50?'Needs improvement':'Critical work needed'));
        var html='<section class="ilsm-panel ilsm-onpage-dashboard"><div class="ilsm-onpage-dashboard-main"><div class="ilsm-onpage-score ilsm-score-gauge--'+tone(score)+'"><strong>'+escapeValue(score)+'</strong><span>/100</span></div><div class="ilsm-onpage-dashboard-copy"><span class="ilsm-onpage-eyebrow">Rendered page audit</span><h2>'+escapeValue(data.title)+'</h2><a href="'+escapeValue(data.url)+'" target="_blank" rel="noopener noreferrer">'+escapeValue(data.url)+'</a><div class="ilsm-onpage-keyphrase-chip"><span>Focus keyphrase</span><strong>'+escapeValue(data.keyphrase)+'</strong></div></div></div><div class="ilsm-onpage-dashboard-side"><span class="ilsm-onpage-score-label is-'+tone(score)+'">'+escapeValue(scoreLabel)+'</span><div class="ilsm-onpage-counts"><button type="button" class="is-fail" data-audit-filter="fail"><b>'+escapeValue(data.counts.fail||0)+'</b><span>Errors</span></button><button type="button" class="is-warning" data-audit-filter="warning"><b>'+escapeValue(data.counts.warning||0)+'</b><span>Warnings</span></button><button type="button" class="is-pass" data-audit-filter="pass"><b>'+escapeValue(data.counts.pass||0)+'</b><span>Passed</span></button></div></div></section>';
        var fixes=(data.checks||[]).filter(function(check){return (check.status==='fail'||check.status==='warning')&&((parseInt(check.weight,10)||0)>0||check.action_url);}).sort(function(a,b){if(a.status!==b.status){return a.status==='fail'?-1:1;}return (parseInt(b.weight,10)||0)-(parseInt(a.weight,10)||0);});
        html+='<section class="ilsm-panel ilsm-onpage-fix-summary" aria-labelledby="ilsm-onpage-fix-title"><div class="ilsm-onpage-fix-head"><div><span class="ilsm-onpage-eyebrow">Action plan</span><h2 id="ilsm-onpage-fix-title">What to fix for better SEO</h2><p>Every item below comes directly from this rendered-page audit. Fix errors first, then assess warnings in editorial context.</p></div><span class="ilsm-onpage-fix-total">'+escapeValue(fixes.length)+' actions</span></div>';
        if(!fixes.length){html+='<div class="ilsm-onpage-all-passed"><i class="fa fa-check-circle" aria-hidden="true"></i><strong>No failed checks or warnings were found.</strong><span>Continue reviewing usefulness, accuracy, search intent and conversion quality.</span></div>';}else{html+='<div class="ilsm-onpage-action-columns">';['fail','warning'].forEach(function(state){var items=fixes.filter(function(check){return check.status===state;});html+='<section class="is-'+state+'"><div class="ilsm-onpage-action-title"><i class="fa '+icon(state)+'" aria-hidden="true"></i><div><strong>'+(state==='fail'?'Fix first':'Review next')+'</strong><span>'+items.length+' '+(state==='fail'?'errors':'warnings')+'</span></div></div>';if(!items.length){html+='<p class="ilsm-onpage-action-empty">No '+(state==='fail'?'errors':'warnings')+' detected.</p>';}else{html+='<div class="ilsm-onpage-action-list">';items.forEach(function(check){html+='<a href="#'+escapeValue(check._auditId)+'"><span><strong>'+escapeValue(check.label)+'</strong><small>'+escapeValue(check.evidence)+'</small></span><i class="fa fa-arrow-right" aria-hidden="true"></i></a>';});html+='</div>';}html+='</section>';});html+='</div>';}
        html+='</section>';
        html+='<div class="ilsm-onpage-notice"><i class="fa fa-info-circle" aria-hidden="true"></i>'+escapeValue(data.notice)+'</div>';
        html+='<nav class="ilsm-onpage-section-nav" aria-label="Audit sections"><span>Detailed checks</span>';Object.keys(categories).forEach(function(key){var checks=grouped[key]||[];if(!checks.length){return;}html+='<a href="#ilsm-onpage-group-'+escapeValue(key)+'">'+escapeValue(categories[key])+'<b>'+escapeValue(issueCount(checks))+'</b></a>';});html+='</nav><div class="ilsm-onpage-detail-groups">';
        Object.keys(categories).forEach(function(key){var checks=grouped[key]||[];if(!checks.length){return;}var problems=issueCount(checks),passed=checks.filter(function(check){return check.status==='pass';}).length;html+='<details id="ilsm-onpage-group-'+escapeValue(key)+'" class="ilsm-panel ilsm-onpage-category" '+(problems?'open':'')+'><summary><span><i class="fa '+(problems?'fa-exclamation-circle':'fa-check-circle')+'" aria-hidden="true"></i><strong>'+escapeValue(categories[key])+'</strong></span><span class="ilsm-onpage-category-meta">'+(problems?'<b>'+escapeValue(problems)+' to review</b>':'<b class="is-pass">All clear</b>')+'<small>'+escapeValue(passed)+' passed</small><i class="fa fa-angle-down" aria-hidden="true"></i></span></summary><div class="ilsm-onpage-checks">';checks.forEach(function(check){var action=check.action_url?'<a class="ilsm-btn ilsm-btn-small ilsm-onpage-check-action" href="'+escapeValue(check.action_url)+'">'+escapeValue(check.action_label||'Review opportunities')+' <i class="fa fa-arrow-right" aria-hidden="true"></i></a>':'';html+='<article id="'+escapeValue(check._auditId)+'" class="is-'+escapeValue(check.status)+'" data-audit-state="'+escapeValue(check.status)+'"><i class="fa '+icon(check.status)+'" aria-hidden="true"></i><div><div class="ilsm-onpage-check-head"><strong>'+escapeValue(check.label)+'</strong><span>'+escapeValue(check.status)+'</span></div><p>'+escapeValue(check.evidence)+'</p><small>'+escapeValue(check.recommendation)+'</small>'+action+'</div></article>';});html+='</div></details>';});html+='</div>';
        results.html(html).prop('hidden',false);
        results.off('click.ilsmAudit').on('click.ilsmAudit','[data-audit-filter]',function(){var state=$(this).attr('data-audit-filter'),first=results.find('[data-audit-state="'+state+'"]').first();if(!first.length){return;}var group=first.closest('details').get(0);if(group){group.open=true;}first.get(0).scrollIntoView({behavior:'smooth',block:'center'});});
        results.on('click.ilsmAudit','.ilsm-onpage-section-nav a',function(){var target=document.querySelector($(this).attr('href'));if(target&&target.tagName==='DETAILS'){target.open=true;}});
        openHashTarget();
    }
    $(window).on('hashchange.ilsmAudit',openHashTarget);
    form.on('submit',function(event){
        event.preventDefault();
        var button=form.find('button[type="submit"]');button.prop('disabled',true).addClass('is-busy');results.prop('hidden',true).empty();status.attr('class','ilsm-onpage-status is-loading').html('<i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i> Fetching and analyzing rendered public HTML…');
        $.ajax({url:ILSM.ajax,method:'POST',timeout:30000,data:{action:'ilsm_on_page_audit',nonce:ILSM.nonce,url:$('#ilsm-onpage-url').val(),keyphrase:$('#ilsm-onpage-keyphrase').val()}})
            .done(function(response){if(response&&response.success&&response.data){status.empty().attr('class','ilsm-onpage-status');render(response.data);}else{status.attr('class','ilsm-onpage-status is-error').text(response&&response.data&&response.data.message?response.data.message:'The audit could not be completed.');}})
            .fail(function(xhr,state){var message=state==='timeout'?'The audit timed out while requesting the public page.':(xhr&&xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message?xhr.responseJSON.data.message:'The audit request failed.');status.attr('class','ilsm-onpage-status is-error').text(message);})
            .always(function(){button.prop('disabled',false).removeClass('is-busy');});
    });
});


/* External Link Health workspace. Explicit reviewed actions only. */
jQuery(function($){
    'use strict';
    function ilsmT(key, fallback){ return ilsmAdminT(key, fallback); }
    function ilsmFmt(key, fallback, value){ return ilsmT(key, fallback).replace('%s', String(value)); }
    function ilsmFmtN(key, fallback, values){
        var out=ilsmT(key,fallback);
        (values||[]).forEach(function(value,index){
            out=out.replace('%'+(index+1)+'$s',String(value));
        });
        return out;
    }
    $(document).on('click','[data-external-tab]',function(){
        var tab=$(this).data('external-tab');
        $('[data-external-tab]').removeClass('is-active').attr('aria-selected','false');
        $(this).addClass('is-active').attr('aria-selected','true');
        $('[data-external-panel]').removeClass('is-active').attr('hidden',true);
        $('[data-external-panel="'+tab+'"]').addClass('is-active').removeAttr('hidden');
    });
    $(document).on('input','.ilsm-external-search',function(){
        var q=String($(this).val()||'').toLowerCase().trim();
        $('.ilsm-external-table tbody tr[data-search]').each(function(){
            $(this).toggle(!q || String($(this).data('search')||'').indexOf(q)!==-1);
        });
    });
    function updateExternalBulkState(){
        var selected=$('.ilsm-external-row-check:checked').length;
        $('#ilsm-external-bulk-action,#ilsm-external-bulk-apply').prop('disabled',selected===0);
        $('#ilsm-external-bulk-status').text(selected?ilsmFmt('externalSelected','%s selected',selected):'');
        var total=$('.ilsm-external-row-check').length;
        $('#ilsm-external-select-all').prop('checked',total>0&&selected===total).prop('indeterminate',selected>0&&selected<total);
        if(selected===0){$('#ilsm-external-bulk-action').val('');}
    }
    $(document).on('change','#ilsm-external-select-all',function(){
        $('.ilsm-external-row-check').prop('checked',$(this).prop('checked'));
        updateExternalBulkState();
    });
    $(document).on('change','.ilsm-external-row-check',updateExternalBulkState);

    // Report-only Ignore controls live beside content-editing actions but use a
    // separate endpoint. Building them here keeps legacy row markup compatible.
    $('.ilsm-external-table-premium tbody tr').each(function(){
        var row=$(this),box=row.find('.ilsm-external-row-check').first(),menu=row.find('.ilsm-action-menu-popover').first();
        var linkId=parseInt(box.data('link-id'),10)||0;
        if(!linkId||!menu.length||menu.find('.ilsm-ignore-link').length){return;}
        var separator=$('<div>',{'class':'ilsm-menu-separator','aria-hidden':'true'});
        var occurrence=$('<button>',{type:'button','class':'ilsm-action-menu-item ilsm-ignore-link','data-link-id':linkId,'data-mode':'ignore_occurrence'})
            .append($('<i>',{'class':'fa fa-eye-slash','aria-hidden':'true'}))
            .append($('<span>').append($('<strong>').text(ilsmT('ignoreOccurrence','Ignore this occurrence'))).append($('<small>').text(ilsmT('ignoreOccurrenceHelp','Hide only this scanned occurrence from the report.'))));
        menu.append(separator,occurrence);
        if(window.ILSM&&ILSM.canManageSettings){
            menu.append($('<button>',{type:'button','class':'ilsm-action-menu-item ilsm-ignore-link','data-link-id':linkId,'data-mode':'ignore_domain'})
                .append($('<i>',{'class':'fa fa-globe','aria-hidden':'true'}))
                .append($('<span>').append($('<strong>').text(ilsmT('ignoreDomain','Ignore this domain'))).append($('<small>').text(ilsmT('ignoreDomainHelp','Hide this hostname across External Link Health reports.')))));
        }
    });


    function approveExternalDomains(domains,approveAll,skipConfirm){
        if(!window.ILSM || !ILSM.externalNonce){
            var runtimeMessage=ilsmT('externalRuntimeUnavailable','External Link Health could not start because its security data is unavailable. Reload this page and try again.');
            $('#ilsm-external-bulk-status').text(runtimeMessage);
            window.alert(runtimeMessage);
            return;
        }
        domains=Array.from(new Set((domains||[]).map(function(domain){return String(domain||'').toLowerCase().trim();}).filter(Boolean)));
        if(!approveAll&&!domains.length){
            var domainMessage=ilsmT('noValidDomainsSelected','No valid external domains were found in the selected rows.');
            $('#ilsm-external-bulk-status').text(domainMessage);
            window.alert(domainMessage);
            return;
        }
        var count=approveAll?parseInt($('#ilsm-approve-all-external-domains').data('count'),10)||0:domains.length;
        var confirmText=approveAll
            ? ilsmFmt('approveAllDomainsConfirm','Approve all %s external domains currently needing review? This adds them to Approved domains.',count)
            : ilsmFmt('approveSelectedDomainsConfirm','Approve %s selected external domains? This adds them to Approved domains.',count);
        if(!skipConfirm&&!window.confirm(confirmText)){return;}
        var buttons=$('#ilsm-external-bulk-apply,#ilsm-approve-all-external-domains,#ilsm-domain-review-approve');
        buttons.prop('disabled',true).addClass('is-busy');
        $('#ilsm-domain-bulk-status,#ilsm-external-bulk-status,#ilsm-domain-review-status').text(ilsmT('approvingDomains','Approving domains…'));
        $.post(ILSM.ajax,{action:'ilsm_external_approve_domains',nonce:ILSM.externalNonce,domains:domains,approve_all:approveAll?1:0})
            .done(function(response){
                if(response&&response.success){
                    var message=response.data&&response.data.message?response.data.message:ilsmT('domainsApproved','Domains approved.');
                    $('#ilsm-domain-bulk-status,#ilsm-external-bulk-status,#ilsm-domain-review-status').text(message);
                    window.setTimeout(function(){window.location.reload();},350);
                }else{
                    var message=response&&response.data&&response.data.message?response.data.message:ilsmT('approveDomainsFailed','The domains could not be approved.');
                    $('#ilsm-domain-bulk-status,#ilsm-external-bulk-status,#ilsm-domain-review-status').text(message);
                    window.alert(message);
                }
            }).fail(function(xhr){
                var message=xhr&&xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message?xhr.responseJSON.data.message:ilsmT('approveDomainsFailed','The domains could not be approved.');
                $('#ilsm-domain-bulk-status,#ilsm-external-bulk-status,#ilsm-domain-review-status').text(message);
                window.alert(message);
            }).always(function(){buttons.removeClass('is-busy').prop('disabled',false);updateExternalBulkState();});
    }

    $(document).on('click','#ilsm-approve-all-external-domains',function(){approveExternalDomains([],true);});
    $(document).on('click','#ilsm-external-bulk-apply',function(event){
        event.preventDefault();
        event.stopPropagation();
        var mode=String($('#ilsm-external-bulk-action').val()||'');
        var selected=$('.ilsm-external-row-check:checked');
        if(!mode||!selected.length){
            var selectionMessage=ilsmT('chooseExternalBulkAction','Select at least one link and choose a bulk action.');
            $('#ilsm-external-bulk-status').text(selectionMessage);
            window.alert(selectionMessage);
            return;
        }
        $('#ilsm-external-bulk-status').text(ilsmT('startingBulkAction','Starting bulk action…'));
        if(mode==='approve'){
            var domains=selected.map(function(){
                var parser=document.createElement('a');
                parser.href=String($(this).data('url')||'');
                return parser.hostname||'';
            }).get();
            approveExternalDomains(domains,false);
            return;
        }
        if(mode==='ignore_occurrence'||mode==='ignore_domain'){
            runExternalIgnoreBulk(mode,selected);
            return;
        }
        runExternalBulk(mode);
    });

    function runExternalIgnoreBulk(mode,selected){
        var items=selected.map(function(){return {box:$(this),linkId:parseInt($(this).data('link-id'),10)||0};}).get().filter(function(item){return item.linkId>0;});
        var label=mode==='ignore_domain'?ilsmT('ignoreSelectedDomains','Ignore the domains represented by the selected links? This changes reports only.'):ilsmT('ignoreSelectedOccurrences','Ignore the selected link occurrences? This changes reports only.');
        if(!items.length||!window.confirm(label)){return;}
        var done=0,failed=0,index=0,buttons=$('#ilsm-external-bulk-apply,#ilsm-external-bulk-action');
        buttons.prop('disabled',true).addClass('is-busy');
        function next(){
            if(index>=items.length){$('#ilsm-external-bulk-status').text(done+' '+ilsmT('ignored','ignored')+' · '+failed+' '+ilsmT('ignoreFailedCount','failed'));window.setTimeout(function(){window.location.reload();},350);return;}
            var item=items[index++];
            $.post(ILSM.ajax,{action:'ilsm_external_ignore_action',nonce:ILSM.externalNonce,link_id:item.linkId,mode:mode}).done(function(response){if(response&&response.success){done++;item.box.closest('tr').fadeOut(120);}else{failed++;}}).fail(function(){failed++;}).always(function(){$('#ilsm-external-bulk-status').text((done+failed)+' / '+items.length);next();});
        }
        next();
    }

    const domainOperationHistoryKey='ilsm_external_domain_operation_history';
    let pendingDomainAction=null;
    let activeDomainOperation=null;

    function domainActionMeta(mode){
        const map={
            nofollow:{label:ilsmT('setAllNofollow','Set all to nofollow'),description:ilsmT('domainSetAllNofollow','add nofollow to all editable occurrences while preserving other rel values'),icon:'fa-shield',danger:false},
            follow:{label:ilsmT('setAllDofollow','Set all to dofollow'),description:ilsmT('domainSetAllDofollow','remove only the nofollow token from all editable occurrences'),icon:'fa-link',danger:false},
            unlink:{label:ilsmT('unlinkAllOccurrences','Unlink all occurrences'),description:ilsmT('domainUnlinkAll','unlink all safely editable occurrences and keep their anchor text'),icon:'fa-unlink',danger:true},
            replace:{label:ilsmT('replaceAllRemoved','Replace all with [Removed Link]'),description:ilsmT('domainReplaceAll','replace all safely editable occurrences with [Removed Link]'),icon:'fa-ban',danger:true},
            ignore_occurrences:{label:ilsmT('ignoreCurrentOccurrences','Ignore current occurrences'),description:ilsmT('domainIgnoreOccurrences','hide the current scanned occurrences from this report without changing page content'),icon:'fa-eye-slash',danger:false},
            ignore_domain:{label:ilsmT('ignoreDomain','Ignore this domain'),description:ilsmT('domainIgnoreDomain','hide this hostname across External Link Health reports without changing page content'),icon:'fa-globe',danger:false}
        };
        return map[mode]||null;
    }

    function openDomainActionModal(button){
        if(!window.ILSM||!ILSM.externalNonce){
            showDomainFeedback(ilsmT('externalRuntimeUnavailable','External Link Health could not start because its security data is unavailable. Reload this page and try again.'),'error',true);
            return;
        }
        const domain=String(button.data('domain')||'').toLowerCase().trim();
        const mode=String(button.data('mode')||'');
        const count=parseInt(button.data('count'),10)||0;
        const meta=domainActionMeta(mode);
        if(!domain||!meta){return;}
        pendingDomainAction={button:button,domain:domain,mode:mode,count:count,meta:meta};
        const modal=$('#ilsm-domain-action-modal');
        modal.toggleClass('is-danger',!!meta.danger).prop('hidden',false);
        modal.find('.ilsm-domain-action-modal-icon i').attr('class','fa '+meta.icon);
        $('#ilsm-domain-action-modal-domain').text(domain);
        $('#ilsm-domain-action-modal-count').text(count);
        $('#ilsm-domain-action-modal-action').text(meta.label);
        $('#ilsm-domain-action-modal-description').text(meta.description+'.');
        $('#ilsm-domain-action-modal-confirm').toggleClass('ilsm-btn-danger',!!meta.danger).text(meta.danger?ilsmT('confirmDestructiveOperation','Confirm destructive operation'):ilsmT('confirmOperation','Confirm operation'));
        button.closest('details').prop('open',false);
        window.setTimeout(function(){$('#ilsm-domain-action-modal-confirm').trigger('focus');},20);
    }

    function closeDomainActionModal(){
        const trigger=pendingDomainAction&&pendingDomainAction.button?pendingDomainAction.button:null;
        $('#ilsm-domain-action-modal').prop('hidden',true).removeClass('is-danger');
        pendingDomainAction=null;
        if(trigger&&trigger.length){trigger.trigger('focus');}
    }

    function renderDomainOperationHistory(){
        let items=[];
        try{items=JSON.parse(window.sessionStorage.getItem(domainOperationHistoryKey)||'[]');}catch(e){items=[];}
        const box=$('#ilsm-domain-operation-history'),list=box.find('ul').empty();
        if(!Array.isArray(items)||!items.length){box.prop('hidden',true);return;}
        items.slice(0,5).forEach(function(item){
            const li=$('<li>');
            $('<time>').text(item.time||'').appendTo(li);
            $('<strong>').text(item.domain||'').appendTo(li);
            $('<span>').text(item.label||'').appendTo(li);
            $('<small>').text(item.summary||'').appendTo(li);
            li.addClass('is-'+(item.type||'info'));
            list.append(li);
        });
        box.prop('hidden',false);
    }

    function addDomainOperationHistory(op,type,summary){
        let items=[];
        try{items=JSON.parse(window.sessionStorage.getItem(domainOperationHistoryKey)||'[]');}catch(e){items=[];}
        if(!Array.isArray(items)){items=[];}
        const now=new Date();
        items.unshift({time:now.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}),domain:op.domain,label:op.meta.label,summary:summary,type:type});
        items=items.slice(0,5);
        try{window.sessionStorage.setItem(domainOperationHistoryKey,JSON.stringify(items));}catch(e){}
        renderDomainOperationHistory();
    }

    function setDomainOperationPanel(op,state,message){
        const panel=$('#ilsm-domain-operation');
        panel.data('retry-domain',op.domain).data('retry-mode',op.mode);
        const pct=op.count>0?Math.min(100,Math.round((op.processed/op.count)*100)):(state==='success'?100:0);
        const phase=op.phase||(state==='running'?'inspect':'verify');
        panel.prop('hidden',false).removeClass('is-running is-success is-warning is-error is-info is-stopped').addClass('is-'+state);
        panel.attr('data-ilsm-domain-phase',phase);
        panel.find('.ilsm-domain-operation-ring').css('--ilsm-progress',(pct*3.6)+'deg');
        panel.find('.ilsm-domain-operation-icon i').attr('class','fa '+(state==='running'?'fa-refresh fa-spin':state==='success'?'fa-check':state==='error'?'fa-times':state==='warning'||state==='stopped'?'fa-exclamation-triangle':'fa-info-circle'));
        $('#ilsm-domain-operation-title').text(state==='running'?(phase==='inspect'?ilsmT('checkingRelStatus','Checking current rel status'):ilsmFmt('domainActionWorking','%s in progress',op.meta.label)):op.meta.label);
        $('#ilsm-domain-operation-subtitle').text(op.domain+' · '+op.count+' '+ilsmT('currentOccurrences','current occurrences'));
        $('#ilsm-domain-operation-state').text(state==='running'?ilsmT('processing','Processing'):state==='success'?ilsmT('success','Success'):state==='warning'?ilsmT('completedWithWarnings','Completed with warnings'):state==='error'?ilsmT('failed','Failed'):state==='stopped'?ilsmT('stoppedSafely','Stopped safely'):ilsmT('noChanges','No changes'));
        $('#ilsm-domain-operation-progress-bar').css('width',pct+'%');
        $('#ilsm-domain-operation-percent').text(pct+'%');
        panel.find('.ilsm-domain-operation-progress-track').attr('aria-valuenow',pct);
        $('#ilsm-domain-operation-processed').text(op.inspected||op.processed);
        $('#ilsm-domain-operation-updated').text(op.updated);
        $('#ilsm-domain-operation-already').text(op.already);
        $('#ilsm-domain-operation-skipped').text(op.skipped);
        $('#ilsm-domain-operation-detail').text(message||'');
        $('#ilsm-domain-operation-stop').prop('hidden',state!=='running').prop('disabled',!!op.stopRequested);
        $('#ilsm-domain-operation-retry').prop('hidden',!(state==='error'||state==='stopped'));
        $('#ilsm-domain-operation-scan').prop('hidden',!(state==='success'||state==='warning'||state==='stopped')||['replace','unlink'].indexOf(op.mode)===-1);
        $('#ilsm-domain-operation-refresh').prop('hidden',state==='running');
        $('#ilsm-domain-operation-dismiss').prop('hidden',state==='running');
    }

    function appendDomainLiveItem(item){
        if(!item||!item.source_title){return;}
        const box=$('#ilsm-domain-live-activity'),list=box.find('ul');
        const outcome=String(item.outcome||'checked');
        const labels={changed:ilsmT('changed','Changed'),already:ilsmT('alreadyCorrect','Already correct'),manual:ilsmT('needsManualEdit','Needs manual edit'),checked:ilsmT('checked','Checked')};
        const icons={changed:'fa-check',already:'fa-check-circle-o',manual:'fa-exclamation-triangle',checked:'fa-search'};
        const li=$('<li>').addClass('is-'+outcome);
        $('<i>').addClass('fa '+(icons[outcome]||icons.checked)).attr('aria-hidden','true').appendTo(li);
        const copy=$('<span>').appendTo(li);
        $('<strong>').text(item.source_title).appendTo(copy);
        $('<small>').text((item.location?item.location+' · ':'')+(item.target_url||'')).appendTo(copy);
        const result=$('<span>').addClass('ilsm-domain-live-result').appendTo(li);
        $('<b>').text(labels[outcome]||labels.checked).appendTo(result);
        $('<small>').text(item.message||'').appendTo(result);
        list.prepend(li);
        list.children().slice(5).remove();
        box.prop('hidden',false);
    }

    function releaseDomainOperation(op,done){
        if(!op.operationToken||!op.scanId){done&&done();return;}
        $.post(ILSM.ajax,{action:'ilsm_external_domain_action_cancel',nonce:ILSM.externalNonce,domain:op.domain,operation_token:op.operationToken,scan_id:op.scanId}).always(function(){done&&done();});
    }

    function completeDomainOperation(op,message){
        // Exhausting the filtered queue means every inspected occurrence has
        // been classified, including duplicate rendered occurrences updated by
        // one stored-source mutation.
        if(op.count>0){op.processed=op.count;}
        op.phase='verify';
        op.card.removeClass('is-domain-action-running');
        op.card.find('.ilsm-domain-link-action').prop('disabled',false);
        op.button.removeClass('is-busy');
        let type='success';
        let finalMessage=message||ilsmFmtN('domainActionFinished','%1$s processed · %2$s updated · %3$s already correct · %4$s unsupported',[op.processed,op.updated,op.already,op.skipped]);
        if(op.errors.length){
            type='warning';
            finalMessage=ilsmFmtN('domainActionFinishedWarnings','Completed with warnings: %1$s processed · %2$s updated · %3$s already correct · %4$s unsupported. %5$s',[op.processed,op.updated,op.already,op.skipped,op.errors[0]]);
        }else if(op.processed>0&&op.updated===0&&op.mode!=='ignore_domain'){
            type='info';
            finalMessage=op.already>0&&op.skipped===0?ilsmFmtN('domainActionAlreadyCorrect','Completed: all %1$s processed occurrences were already in the requested state.',[op.processed]):ilsmFmtN('domainActionNoChanges','Completed: %1$s processed · %2$s already correct · %3$s unsupported.',[op.processed,op.already,op.skipped]);
        }else if(op.skipped>0){
            type='warning';
            finalMessage=ilsmFmtN('domainActionPartial','Partially completed: %1$s processed · %2$s updated · %3$s already correct · %4$s unsupported.',[op.processed,op.updated,op.already,op.skipped]);
        }
        setDomainOperationPanel(op,type==='info'?'info':type,finalMessage);
        op.status.text(finalMessage);
        showDomainFeedback(finalMessage,type,true);
        addDomainOperationHistory(op,type,op.updated+' '+ilsmT('updated','updated')+' · '+op.already+' '+ilsmT('alreadyCorrect','already correct')+' · '+op.skipped+' '+ilsmT('unsupported','unsupported'));
        activeDomainOperation=null;
    }

    function failDomainOperation(op,reason){
        op.phase=op.phase||'inspect';
        op.card.removeClass('is-domain-action-running');
        op.card.find('.ilsm-domain-link-action').prop('disabled',false);
        op.button.removeClass('is-busy');
        op.status.text(reason);
        releaseDomainOperation(op,function(){});
        setDomainOperationPanel(op,'error',reason);
        showDomainFeedback(reason,'error',true);
        addDomainOperationHistory(op,'error',reason);
        activeDomainOperation=null;
    }

    function stopDomainOperation(op){
        op.stopRequested=true;
        $('#ilsm-domain-operation-stop').prop('disabled',true).text(ilsmT('stoppingSafely','Stopping after this batch…'));
        if(!op.requestActive){
            releaseDomainOperation(op,function(){
                op.card.removeClass('is-domain-action-running');
                op.card.find('.ilsm-domain-link-action').prop('disabled',false);
                op.button.removeClass('is-busy');
                const message=ilsmT('domainActionStopped','The operation was stopped safely after the last completed batch. You can retry later; every source will be revalidated again.');
                setDomainOperationPanel(op,'stopped',message);
                addDomainOperationHistory(op,'warning',message);
                activeDomainOperation=null;
            });
        }
    }

    function executeDomainAction(config){
        if(activeDomainOperation){
            showDomainFeedback(ilsmT('domainOperationAlreadyRunning','A domain operation is already running. Stop it safely or wait for it to finish.'),'warning',true);
            return;
        }
        const button=config.button;
        const menu=button.closest('.ilsm-domain-action-menu');
        const card=button.closest('.ilsm-domain-card');
        const op={button:button,menu:menu,card:card,status:menu.find('.ilsm-domain-action-status'),domain:config.domain,mode:config.mode,count:config.count,meta:config.meta,cursor:0,scanId:0,operationToken:'',processed:0,inspected:0,updated:0,already:0,skipped:0,batch:0,errors:[],phase:'inspect',stopRequested:false,requestActive:false};
        activeDomainOperation=op;
        card.addClass('is-domain-action-running');
        card.find('.ilsm-domain-link-action').prop('disabled',true);
        button.addClass('is-busy');
        $('#ilsm-domain-live-activity').prop('hidden',true).find('ul').empty();
        op.status.text(ilsmT('domainActionStarting','Starting…'));
        $('#ilsm-domain-operation-stop').text(ilsmT('stopSafely','Stop safely'));
        setDomainOperationPanel(op,'running',ilsmT('domainActionInspecting','Checking the current rel status before making changes…'));

        function next(){
            if(op.stopRequested){stopDomainOperation(op);return;}
            op.phase='change';
            op.requestActive=true;
            op.batch++;
            setDomainOperationPanel(op,'running',ilsmFmtN('domainActionItemProgress','Validating stored source %1$s of %2$s…',[Math.min(op.count,op.processed+1),op.count]));
            const requestStarted=Date.now();
            const heartbeat=window.setInterval(function(){
                const elapsed=Math.max(1,Math.round((Date.now()-requestStarted)/1000));
                setDomainOperationPanel(op,'running',ilsmFmtN('domainActionValidatingSource','Validating source in batch %1$s · %2$s seconds elapsed…',[op.batch,elapsed]));
            },1000);
            $.ajax({url:ILSM.ajax,method:'POST',timeout:45000,data:{action:'ilsm_external_domain_action',nonce:ILSM.externalNonce,domain:op.domain,mode:op.mode,cursor:op.cursor,scan_id:op.scanId,operation_token:op.operationToken}}).done(function(response){
                window.clearInterval(heartbeat);
                op.requestActive=false;
                if(!response||!response.success){
                    const reason=response&&response.data&&response.data.message?response.data.message:ilsmT('domainActionFailed','The domain action could not be completed safely.');
                    failDomainOperation(op,reason);return;
                }
                const data=response.data||{};
                op.scanId=parseInt(data.scan_id,10)||op.scanId;
                op.operationToken=String(data.operation_token||op.operationToken||'');
                op.processed+=parseInt(data.processed,10)||0;
                op.updated+=parseInt(data.updated,10)||0;
                op.already+=parseInt(data.already,10)||0;
                op.skipped+=parseInt(data.skipped,10)||0;
                if(Array.isArray(data.errors)){op.errors=op.errors.concat(data.errors).slice(0,10);}
                if(data.item){appendDomainLiveItem(data.item);}
                if(data.cursor){op.cursor=parseInt(data.cursor,10)||op.cursor;}
                op.status.text(ilsmFmtN('domainActionProgress','%1$s processed · %2$s updated · %3$s already correct · %4$s unsupported',[op.processed,op.updated,op.already,op.skipped]));
                setDomainOperationPanel(op,'running',ilsmFmtN('domainActionProgress','%1$s processed · %2$s updated · %3$s already correct · %4$s unsupported',[op.processed,op.updated,op.already,op.skipped]));
                if(data.done){completeDomainOperation(op,'');return;}
                if(op.stopRequested){stopDomainOperation(op);return;}
                window.setTimeout(next,100);
            }).fail(function(xhr,statusText){
                window.clearInterval(heartbeat);
                op.requestActive=false;
                const serverText=xhr&&xhr.responseText?String(xhr.responseText).replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().slice(0,240):'';
                const reason=xhr&&xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message?xhr.responseJSON.data.message:(statusText==='timeout'?ilsmT('domainActionTimedOut','The first editable source took longer than 45 seconds. It may be generated by a slow or unsupported Elementor template. No unverified content was changed; retry after checking the source template.'):(serverText||ilsmT('domainActionFailed','The domain action could not be completed safely.')));
                failDomainOperation(op,reason);
            });
        }
        if(['follow','nofollow'].indexOf(op.mode)!==-1){
            const inspectedAlready=parseInt(op.button.attr('data-'+op.mode),10)||0;
            op.already=Math.min(op.count,inspectedAlready);
            op.processed=op.already;
            op.inspected=op.count;
            op.needsChange=Math.max(0,op.count-op.already);
            setDomainOperationPanel(op,'running',ilsmFmtN('domainInspectionComplete','Status checked: %1$s already correct · %2$s need a change. Processing changes one at a time…',[op.already,op.needsChange]));
            if(op.needsChange===0){completeDomainOperation(op,'');return;}
            window.setTimeout(next,150);
        }else{
            window.setTimeout(next,0);
        }
        // Feedback is secondary to the operation. Schedule the first source
        // before rendering it so a third-party admin notice/filter cannot leave
        // the real mutation queue frozen at 0%.
        try{
            showDomainFeedback(ilsmT('domainActionInspecting','Checking the current rel status before making changes…'),'info',false);
        }catch(feedbackError){
            if(window.console&&window.console.warn){window.console.warn('DMA InternLink Mapper feedback could not render.',feedbackError);}
        }
    }

    $(document).on('click','.ilsm-domain-link-action',function(e){
        e.preventDefault();
        openDomainActionModal($(this));
    });
    $(document).on('click','[data-ilsm-domain-modal-close]',function(){closeDomainActionModal();});
    $(document).on('click','#ilsm-domain-action-modal-confirm',function(){
        if(!pendingDomainAction){return;}
        const config=pendingDomainAction;
        $('#ilsm-domain-action-modal').prop('hidden',true).removeClass('is-danger');
        pendingDomainAction=null;
        executeDomainAction(config);
    });
    $(document).on('keydown',function(e){const modal=$('#ilsm-domain-action-modal');if(modal.prop('hidden')){return;}if(e.key==='Escape'){e.preventDefault();closeDomainActionModal();return;}if(e.key==='Tab'){const focusable=modal.find('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])').filter(':visible');if(!focusable.length){e.preventDefault();return;}const first=focusable.get(0),last=focusable.get(focusable.length-1),active=document.activeElement;if(e.shiftKey&&active===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&active===last){e.preventDefault();first.focus();}}});
    $(document).on('click','#ilsm-domain-operation-stop',function(){if(activeDomainOperation){stopDomainOperation(activeDomainOperation);}});
    $(document).on('click','#ilsm-domain-operation-retry',function(){
        const panel=$('#ilsm-domain-operation');
        const domain=panel.data('retry-domain'),mode=panel.data('retry-mode');
        if(activeDomainOperation){return;}
        const button=$('.ilsm-domain-link-action').filter(function(){return String($(this).data('domain')||'')===String(domain||'')&&String($(this).data('mode')||'')===String(mode||'');}).first();
        if(button.length){openDomainActionModal(button);}
    });
    $(document).on('click','#ilsm-domain-operation-refresh',function(){window.location.reload();});
    $(document).on('submit','#ilsm-managed-redirects form',function(e){
        const message=$(this).find('.ilsm-delete-redirect').attr('data-confirm')||'';
        if(message&&!window.confirm(message)){e.preventDefault();}
    });
    $(document).on('click','#ilsm-domain-operation-dismiss',function(){$('#ilsm-domain-operation').prop('hidden',true);});
    renderDomainOperationHistory();

    $(document).on('click','.ilsm-ignore-link',function(){
        if(!window.ILSM||!ILSM.externalNonce){return;}
        var button=$(this),mode=String(button.data('mode')||''),restore=mode.indexOf('restore_')===0;
        var message=restore?ilsmT('restoreIgnoredConfirm','Restore this report item?'):ilsmT('ignoreReportConfirm','Ignore this report item? Page content and rel attributes will not be changed.');
        if(!window.confirm(message)){return;}
        button.prop('disabled',true).addClass('is-busy');
        $.post(ILSM.ajax,{action:'ilsm_external_ignore_action',nonce:ILSM.externalNonce,link_id:parseInt(button.data('link-id'),10)||0,mode:mode}).done(function(response){if(response&&response.success){window.location.reload();}else{window.alert(response&&response.data&&response.data.message?response.data.message:ilsmT('ignoreActionFailed','The report preference could not be changed.'));button.prop('disabled',false).removeClass('is-busy');}}).fail(function(xhr){window.alert(xhr&&xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message?xhr.responseJSON.data.message:ilsmT('ignoreActionFailed','The report preference could not be changed.'));button.prop('disabled',false).removeClass('is-busy');});
    });

    function updateFollowRow(row,status){
        var follow=status==='nofollow'?'nofollow':'follow';
        var badge=row.find('.ilsm-follow-status');
        badge.attr('data-follow-status',follow).removeClass('is-follow is-nofollow').addClass(follow==='nofollow'?'is-nofollow':'is-follow').text(follow==='nofollow'?ilsmT('nofollow','Nofollow'):ilsmT('dofollow','Dofollow'));
        var toggle=row.find('.ilsm-follow-link').first();
        var nextMode=follow==='nofollow'?'follow':'nofollow';
        toggle.attr('data-mode',nextMode).data('mode',nextMode).prop('disabled',false);
        toggle.find('i').attr('class','fa '+(nextMode==='follow'?'fa-link':'fa-shield'));
        toggle.find('strong').text(nextMode==='follow'?ilsmT('setDofollow','Set dofollow'):ilsmT('setNofollow','Set nofollow'));
        toggle.find('small').text(nextMode==='follow'?ilsmT('removeNofollowOnly','Remove only the nofollow token.'):ilsmT('preserveRelValues','Preserve other rel values.'));
    }

    function runExternalBulk(mode){
        if(!window.ILSM || !ILSM.externalNonce){
            var runtimeMessage=ilsmT('externalRuntimeUnavailable','External Link Health could not start because its security data is unavailable. Reload this page and try again.');
            $('#ilsm-external-bulk-status').text(runtimeMessage);
            window.alert(runtimeMessage);
            return;
        }
        var items=$('.ilsm-external-row-check:checked').map(function(){
            var box=$(this);
            return {box:box,source:box.data('source'),id:box.data('id'),linkId:box.data('link-id'),url:box.data('url'),location:box.data('location')||''};
        }).get();
        if(!items.length){
            var selectionMessage=ilsmT('chooseExternalBulkAction','Select at least one link and choose a bulk action.');
            $('#ilsm-external-bulk-status').text(selectionMessage);
            window.alert(selectionMessage);
            return;
        }
        var labels={
            follow:ilsmT('bulkSetDofollowDescription','set each selected link to dofollow by removing only the nofollow token'),
            nofollow:ilsmT('bulkSetNofollowDescription','add nofollow to each selected link while preserving other rel values'),
            replace:ilsmT('bulkReplaceDescription','replace each selected destination with [Removed Link]'),
            unlink:ilsmT('bulkUnlinkDescription','unlink each selected destination while preserving its anchor text')
        };
        if(!window.confirm(ilsmFmt('bulkActionConfirm','Bulk action: %s? Each link is validated separately before WordPress changes any content.',labels[mode]||mode))){
            $('#ilsm-external-bulk-status').text(ilsmT('bulkActionCancelled','Bulk action cancelled.'));
            return;
        }
        var done=0,failed=0,index=0,failures=[],sharedUpdates={};
        var buttons=$('#ilsm-external-bulk-apply,#ilsm-external-bulk-action');
        buttons.prop('disabled',true).addClass('is-busy');
        $('#ilsm-external-bulk-status').text('0 / '+items.length);
        function finish(){
            buttons.removeClass('is-busy');
            $('#ilsm-external-select-all').prop('checked',false).prop('indeterminate',false);
            updateExternalBulkState();
            var summary=ilsmFmtN('externalBulkFinished','%1$s updated · %2$s skipped/failed',[done,failed]);
            if(failures.length){summary+=' · '+failures[0];}
            $('#ilsm-external-bulk-status').text(summary).attr('title',failures.join('\n'));
            if(failures.length){window.alert(ilsmT('externalBulkFailureDetails','Some links were not changed:')+'\n\n'+failures.slice(0,5).join('\n'));}
        }
        function next(){
            if(index>=items.length){finish();return;}
            var item=items[index++];
            var sharedKey=String(item.url||'')+'|'+String(item.location||'')+'|'+mode;
            if(sharedUpdates[sharedKey]){
                done++;item.box.prop('checked',false);updateFollowRow(item.box.closest('tr'),sharedUpdates[sharedKey]);
                $('#ilsm-external-bulk-status').text(ilsmFmtN('externalBulkProgress','%1$s / %2$s · %3$s skipped/failed',[done+failed,items.length,failed]));next();return;
            }
            $.post(ILSM.ajax,{
                action:'ilsm_external_link_action',nonce:ILSM.externalNonce,source:item.source,id:item.id,link_id:item.linkId,url:item.url,location:item.location,mode:mode
            }).done(function(response){
                if(response&&response.success){
                    done++;
                    item.box.prop('checked',false);
                    if(mode==='follow'||mode==='nofollow'){
                        var resultingStatus=response.data&&response.data.follow_status?response.data.follow_status:mode;
                        updateFollowRow(item.box.closest('tr'),resultingStatus);
                        if(response.data&&response.data.storage==='elementor_template'){sharedUpdates[sharedKey]=resultingStatus;}
                    }else{
                        item.box.closest('tr').addClass('ilsm-row-resolved').fadeOut(160,function(){$(this).remove();});
                    }
                }else{
                    failed++;
                    item.box.closest('tr').addClass('ilsm-row-action-failed');
					var reason=response&&response.data&&response.data.message?response.data.message:ilsmT('linkChangeFailed','The link could not be changed safely.');
					failures.push(reason);item.box.closest('tr').attr('title',reason);
                }
            }).fail(function(xhr){
                failed++;
                item.box.closest('tr').addClass('ilsm-row-action-failed');
				var reason=xhr&&xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message?xhr.responseJSON.data.message:ilsmT('externalActionFailed','The link action failed.');
				failures.push(reason);item.box.closest('tr').attr('title',reason);
            }).always(function(){
                $('#ilsm-external-bulk-status').text(ilsmFmtN('externalBulkProgress','%1$s / %2$s · %3$s skipped/failed',[done+failed,items.length,failed]));
                next();
            });
        }
        next();
    }

    $(document).on('click','.ilsm-follow-link',function(){
        if(!window.ILSM || !ILSM.externalNonce){return;}
        var button=$(this),mode=button.data('mode'),row=button.closest('tr');
        var location=button.data('location')||row.find('.ilsm-external-row-check').data('location')||'';
        var promptText=mode==='nofollow'?ilsmT('confirmSetNofollow','Add nofollow to this external link? Other rel values such as sponsored, ugc, noopener and noreferrer are preserved.'):ilsmT('confirmSetDofollow','Set this external link to dofollow? Only the nofollow token is removed; other rel values are preserved.');
        if(!window.confirm(promptText)){return;}
        row.find('.ilsm-follow-link').prop('disabled',true);
        button.addClass('is-busy');
        $.post(ILSM.ajax,{action:'ilsm_external_link_action',nonce:ILSM.externalNonce,source:button.data('source'),id:button.data('id'),link_id:button.data('link-id'),url:button.data('url'),location:location,mode:mode}).done(function(response){
            if(response&&response.success){
                updateFollowRow(row,response.data&&response.data.follow_status?response.data.follow_status:mode);
                row.find('.ilsm-row-action-menu').removeAttr('open');
                window.alert(response.data&&response.data.message?response.data.message:ilsmT('linkUpdated','Link updated.'));
            }else{
                window.alert(response&&response.data&&response.data.message?response.data.message:ilsmT('linkChangeFailed','The link could not be changed safely.'));
                updateFollowRow(row,row.find('.ilsm-follow-status').attr('data-follow-status')||'follow');
            }
        }).fail(function(xhr){
            var message=ilsmT('externalActionFailed','The link action failed.');
            if(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message){message=xhr.responseJSON.data.message;}
            window.alert(message);
            updateFollowRow(row,row.find('.ilsm-follow-status').attr('data-follow-status')||'follow');
        }).always(function(){button.removeClass('is-busy');});
    });

    $(document).on('click','.ilsm-remove-link',function(){
        if(!window.ILSM || !ILSM.externalNonce){return;}
        var button=$(this), mode=button.data('mode'), replacement=mode==='replace'?'[Removed Link]':ilsmT('plainText','plain text');
        var promptText=ilsmFmt('removeExternalConfirm','Remove this reviewed external link and preserve the surrounding content as %s? A post revision is created when WordPress revisions are enabled.',replacement);
        if(!window.confirm(promptText)){return;}
        button.prop('disabled',true).addClass('is-busy');
        $.post(ILSM.ajax,{
            action:'ilsm_external_link_action',nonce:ILSM.externalNonce,source:button.data('source'),id:button.data('id'),link_id:button.data('link-id'),url:button.data('url'),location:button.data('location')||'',mode:mode
        }).done(function(response){
            if(response&&response.success){
                button.closest('tr').addClass('ilsm-row-resolved').fadeOut(240,function(){$(this).remove();});
                window.alert(response.data&&response.data.message?response.data.message:ilsmT('linkUpdated','Link updated.'));
            }else{
                window.alert(response&&response.data&&response.data.message?response.data.message:ilsmT('linkChangeFailed','The link could not be changed safely.'));
                button.prop('disabled',false).removeClass('is-busy');
            }
        }).fail(function(xhr){
            var message=ilsmT('externalActionFailed','The link action failed.');
            if(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message){message=xhr.responseJSON.data.message;}
            window.alert(message);button.prop('disabled',false).removeClass('is-busy');
        });
    });

    $(document).on('click','#ilsm-domain-review-approve',function(){
        var domain=String($('#ilsm-domain-review-name').text()||'').trim();
        if(domain){approveExternalDomains([domain],false,true);}
    });
    $(document).on('click',function(e){
        if(!$(e.target).closest('.ilsm-row-action-menu').length){$('.ilsm-row-action-menu[open]').removeAttr('open');}
    });
    $(document).on('click','.ilsm-row-action-menu .ilsm-action-menu-item',function(){
        $(this).closest('.ilsm-row-action-menu').removeAttr('open');
    });

    function closeDomainReview(){
        var modal=$('#ilsm-domain-review-modal');
        modal.attr('hidden',true).removeClass('is-open');
        $('body').removeClass('ilsm-modal-open');
        if(closeDomainReview.lastFocus){closeDomainReview.lastFocus.focus();}
    }
    $(document).on('click','.ilsm-review-domain',function(){
        var button=$(this),modal=$('#ilsm-domain-review-modal');
        if(!modal.length){return;}
        closeDomainReview.lastFocus=this;
        $('#ilsm-domain-review-name').text(String(button.data('domain')||''));
        $('#ilsm-domain-review-new-note').prop('hidden',String(button.data('new'))!=='1');
        modal.removeAttr('hidden').addClass('is-open');
        $('body').addClass('ilsm-modal-open');
        modal.find('.ilsm-modal-card').trigger('focus');
    });
    $(document).on('click','[data-ilsm-domain-review-close]',closeDomainReview);
    $(document).on('click','#ilsm-domain-review-modal',function(e){if(e.target===this){closeDomainReview();}});
    $(document).on('keydown',function(e){
        var modal=$('#ilsm-domain-review-modal');
        if(e.key==='Escape'&&!modal.attr('hidden')){closeDomainReview();return;}
        if(e.key==='Tab'&&!modal.attr('hidden')){
            var focusable=modal.find('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])').filter(':visible');
            if(!focusable.length){e.preventDefault();modal.find('.ilsm-modal-card').trigger('focus');return;}
            var first=focusable[0],last=focusable[focusable.length-1];
            if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}
            else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}
        }
    });
});

// Visual Map export helpers: current Knowledge Graph / Page Architecture / Site Architecture.
// Uses only the rendered local admin view. PNG exports keep a transparent canvas background.
(function($){
    'use strict';
    function onReady(fn){ if(document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', fn); } else { fn(); } }
    function escapeHtml(value){ return String(value == null ? '' : value).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]; }); }
    function currentView(){ var p = new URLSearchParams(window.location.search); return p.get('view') || 'link-map'; }
    function currentPostId(){ var p = new URLSearchParams(window.location.search); return p.get('post_id') || ''; }
    function slug(value){ return String(value || 'export').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'').slice(0,90) || 'export'; }
    function downloadDataUrl(dataUrl, filename){ var a=document.createElement('a'); a.href=dataUrl; a.download=filename; document.body.appendChild(a); a.click(); window.setTimeout(function(){ a.remove(); }, 100); }
    function activePanel(target){
        if(target === 'knowledge'){ return document.querySelector('#ilsm-panel-knowledge-graph'); }
        var panel = document.querySelector('.ilsm-map-tabpanel.is-active[data-architecture-mode]');
        if(panel){ return panel; }
        var view = currentView();
        if(view === 'site-architecture'){ return document.querySelector('#ilsm-panel-site-architecture'); }
        return document.querySelector('#ilsm-panel-page-architecture');
    }
    function showExportNotice(button, message, error){
        var panel = button ? button.closest('.ilsm-panel, .ilsm-map-tabpanel') : null;
        var live = panel ? panel.querySelector('.ilsm-visual-export-live') : null;
        if(!live && panel){ live = document.createElement('span'); live.className = 'ilsm-visual-export-live'; live.setAttribute('aria-live','polite'); button.parentNode.appendChild(live); }
        if(live){ live.textContent = message; live.classList.toggle('is-error', !!error); }
    }
    function selectedText(selector, fallback){ var el=document.querySelector(selector); return el ? (el.textContent || '').trim() : fallback; }
    function inlineElementStyles(source, clone){
        if(!source || !clone || source.nodeType !== 1 || clone.nodeType !== 1){ return; }
        var computed = window.getComputedStyle(source);
        var properties = ['color','background','background-color','border','border-radius','box-shadow','font','font-family','font-size','font-weight','line-height','letter-spacing','text-align','display','align-items','justify-content','gap','padding','margin','width','height','min-width','min-height','max-width','max-height','overflow','position','left','top','right','bottom','transform','opacity','fill','stroke','stroke-width','stroke-linecap','stroke-linejoin'];
        var style = '';
        properties.forEach(function(prop){ var v = computed.getPropertyValue(prop); if(v){ style += prop + ':' + v + ';'; } });
        if(style){ clone.setAttribute('style', (clone.getAttribute('style') || '') + ';' + style); }
        for(var i=0; i<source.children.length && i<clone.children.length; i++){ inlineElementStyles(source.children[i], clone.children[i]); }
    }
    function svgElementToDataUrl(svg){
        var clone = svg.cloneNode(true);
        inlineElementStyles(svg, clone);
        clone.setAttribute('xmlns','http://www.w3.org/2000/svg');
        clone.setAttribute('xmlns:xlink','http://www.w3.org/1999/xlink');
        var width = parseFloat(clone.getAttribute('width') || (svg.viewBox && svg.viewBox.baseVal && svg.viewBox.baseVal.width) || svg.getBoundingClientRect().width || 1400);
        var height = parseFloat(clone.getAttribute('height') || (svg.viewBox && svg.viewBox.baseVal && svg.viewBox.baseVal.height) || svg.getBoundingClientRect().height || 820);
        clone.setAttribute('width', String(Math.max(1, Math.round(width))));
        clone.setAttribute('height', String(Math.max(1, Math.round(height))));
        var raw = new XMLSerializer().serializeToString(clone);
        return {src:'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(raw), width:width, height:height};
    }
    function elementForeignObjectDataUrl(el){
        var rect = el.getBoundingClientRect();
        var width = Math.max(900, Math.min(2400, Math.round(rect.width || el.scrollWidth || 1400)));
        var height = Math.max(520, Math.min(2400, Math.round(el.scrollHeight || rect.height || 820)));
        var clone = el.cloneNode(true);
        inlineElementStyles(el, clone);
        clone.setAttribute('xmlns','http://www.w3.org/1999/xhtml');
        clone.style.margin = '0';
        clone.style.background = 'transparent';
        clone.style.width = width + 'px';
        var html = new XMLSerializer().serializeToString(clone);
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'+width+'" height="'+height+'"><foreignObject width="100%" height="100%">'+html+'</foreignObject></svg>';
        return {src:'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg), width:width, height:height};
    }
    function imageSourceToCanvas(info, scale){
        scale = Math.max(1, Math.min(3, scale || 2));
        return new Promise(function(resolve, reject){
            var img = new Image();
            img.onload = function(){
                var canvas = document.createElement('canvas');
                canvas.width = Math.round(info.width * scale);
                canvas.height = Math.round(info.height * scale);
                var ctx = canvas.getContext('2d');
                ctx.setTransform(scale, 0, 0, scale, 0, 0);
                ctx.clearRect(0, 0, info.width, info.height);
                ctx.drawImage(img, 0, 0, info.width, info.height);
                resolve(canvas);
            };
            img.onerror = function(){ reject(new Error('The visual export image could not be rendered.')); };
            img.src = info.src;
        });
    }
    function knowledgeCanvas(){
        var panel = document.querySelector('#ilsm-panel-knowledge-graph');
        if(!panel){ return null; }
        var c3d = panel.querySelector('.ilsm-knowledge-canvas-3d');
        if(c3d && !c3d.hidden){ return c3d; }
        return panel.querySelector('.ilsm-knowledge-canvas');
    }
    function captureKnowledge(){
        return new Promise(function(resolve, reject){
            var c = knowledgeCanvas();
            if(!c || !c.width || !c.height){ reject(new Error('Load the Knowledge Graph before exporting.')); return; }
            var out = document.createElement('canvas');
            out.width = c.width;
            out.height = c.height;
            out.getContext('2d').drawImage(c, 0, 0);
            resolve(out);
        });
    }
    function captureArchitecture(panel){
        return new Promise(function(resolve, reject){
            if(!panel){ reject(new Error('Architecture panel is not available.')); return; }
            var svg = panel.querySelector('.ilsm-architecture-svg');
            if(svg){ imageSourceToCanvas(svgElementToDataUrl(svg), 2).then(resolve).catch(reject); return; }
            var area = panel.querySelector('.ilsm-architecture-canvas');
            if(area){ imageSourceToCanvas(elementForeignObjectDataUrl(area), 2).then(resolve).catch(reject); return; }
            reject(new Error('Load the architecture view before exporting.'));
        });
    }
    function capture(target){
        if(target === 'knowledge'){ return captureKnowledge(); }
        return captureArchitecture(activePanel('architecture'));
    }
    function exportTitle(target){
        var view = currentView();
        if(target === 'knowledge'){ return 'Knowledge Graph'; }
        if(view === 'site-architecture'){ return 'Site Architecture'; }
        return 'Page Architecture';
    }
    function exportSubtitle(target){
        if(target === 'knowledge'){
            var mode = document.querySelector('#ilsm-panel-knowledge-graph .ilsm-knowledge-mode-btn.is-active');
            var density = document.querySelector('#ilsm-panel-knowledge-graph .ilsm-knowledge-limit');
            var depth = document.querySelector('#ilsm-panel-knowledge-graph .ilsm-knowledge-depth');
            return 'Mode: ' + (mode ? mode.textContent.trim() : '2D') + ' · Depth: ' + (depth ? depth.options[depth.selectedIndex].text : 'All') + ' · Density: ' + (density ? density.options[density.selectedIndex].text : 'Balanced');
        }
        var panel = activePanel('architecture');
        var layout = panel ? panel.querySelector('.ilsm-layout-switcher .is-active') : null;
        var mode = panel ? String(panel.getAttribute('data-architecture-mode') || '') : '';
        return 'View: ' + (mode === 'site' ? 'Site Architecture' : 'Page Architecture') + ' · Layout / Style: ' + (layout ? String(layout.getAttribute('data-layout') || '').replace(/-/g,' ') : 'current');
    }
    function metricData(target){
        var panel = target === 'knowledge' ? document.querySelector('#ilsm-panel-knowledge-graph') : activePanel('architecture');
        if(!panel){ return []; }
        var items = [];
        var selectors = target === 'knowledge' ? '.ilsm-knowledge-stat' : '.ilsm-arch-kpi';
        panel.querySelectorAll(selectors).forEach(function(el){
            var strong = el.querySelector('strong');
            var label = el.querySelector('span:not(.ilsm-arch-kpi-icon), small');
            var value = strong ? strong.textContent.trim() : '';
            var name = label ? label.textContent.trim() : '';
            if(value || name){ items.push({label:name, value:value}); }
        });
        return items.slice(0,6);
    }
    function pdfSnapshotCanvas(source){
        var maxWidth = 2200;
        var maxHeight = 1400;
        var ratio = Math.min(1, maxWidth / Math.max(1, source.width), maxHeight / Math.max(1, source.height));
        var out = document.createElement('canvas');
        out.width = Math.max(1, Math.round(source.width * ratio));
        out.height = Math.max(1, Math.round(source.height * ratio));
        var ctx = out.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, out.width, out.height);
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(source, 0, 0, out.width, out.height);
        return out;
    }
    function downloadBlob(blob, filename){
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.setTimeout(function(){ URL.revokeObjectURL(url); }, 1500);
    }
    function exportPng(target, button){
        showExportNotice(button, 'Preparing transparent PNG…');
        capture(target).then(function(canvas){
            var filename = 'dma-internlink-mapper-' + slug(exportTitle(target)) + (currentPostId()?'-post-'+currentPostId():'') + '.png';
            downloadDataUrl(canvas.toDataURL('image/png'), filename);
            showExportNotice(button, 'Transparent PNG exported.');
        }).catch(function(err){ showExportNotice(button, err.message || 'Export failed.', true); });
    }
    function exportPdf(target, button){
        showExportNotice(button, 'Generating PDF report…');
        capture(target).then(function(canvas){
            if(!window.ILSM || !ILSM.visualPdfUrl || !ILSM.visualPdfNonce){
                throw new Error('The PDF export endpoint is unavailable. Reload the admin page and try again.');
            }
            var snapshot = pdfSnapshotCanvas(canvas);
            var view = currentView();
            if(target === 'knowledge'){ view = 'knowledge-graph'; }
            var form = new FormData();
            form.append('action', 'ilsm_export_visual_pdf');
            form.append('_wpnonce', ILSM.visualPdfNonce);
            form.append('view', view);
            form.append('post_id', String(currentPostId() || ''));
            form.append('style', exportSubtitle(target));
            form.append('metrics', JSON.stringify(metricData(target)));
            form.append('image', snapshot.toDataURL('image/jpeg', 0.92));
            return fetch(ILSM.visualPdfUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: form
            }).then(function(response){
                if(!response.ok){
                    return response.text().then(function(text){
                        var clean = String(text || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
                        throw new Error(clean || 'PDF export failed.');
                    });
                }
                return response.blob();
            }).then(function(blob){
                if(!blob || blob.type !== 'application/pdf'){
                    throw new Error('WordPress did not return a valid PDF file.');
                }
                var filename = 'dma-internlink-mapper-' + slug(exportTitle(target)) + (currentPostId()?'-post-'+currentPostId():'') + '.pdf';
                downloadBlob(blob, filename);
                showExportNotice(button, 'PDF exported.');
            });
        }).catch(function(err){ showExportNotice(button, err.message || 'Export failed.', true); });
    }
    function updateObsidianExportLink(link){
        if(!link){ return true; }
        var base = link.getAttribute('data-ilsm-obsidian-base') || link.getAttribute('href') || '';
        var scope = link.getAttribute('data-ilsm-obsidian-scope') || 'page';
        if(scope === 'site'){ return true; }
        var postId = link.getAttribute('data-ilsm-selected-post-id') || '';
        var panel = link.closest('[data-architecture-mode]');
        if(panel){
            var root = panel.querySelector('.ilsm-architecture-root');
            if(root && root.value){ postId = root.value; }
        }
        if(!postId){ postId = currentPostId(); }
        if(!postId){
            window.alert(ilsmT('selectPageObsidian','Select a scanned page before exporting this Obsidian neighborhood.'));
            return false;
        }
        try {
            var url = new URL(base, window.location.href);
            url.searchParams.set('post_id', postId);
            link.href = url.toString();
        } catch(e) { return false; }
        return true;
    }
    function initBrokenLinkMaintenance(){
        var status = document.getElementById('ilsm-broken-status');
        if(!status || !window.ILSM || !ILSM.ajax || !ILSM.brokenNonce){ return; }

        function brokenText(key, fallback){
            return ILSM.strings && ILSM.strings[key] ? ILSM.strings[key] : fallback;
        }
        function postBroken(data){
            return fetch(ILSM.ajax, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams(data)
            }).then(async function(response){
                var json;
                try { json = await response.json(); }
                catch(e){
                    throw new Error(brokenText('brokenInvalidResponse', 'The server returned an invalid response.') + ' (HTTP ' + response.status + ').');
                }
                if(!response.ok || !json.success){
                    throw new Error(json.data && json.data.message ? json.data.message : 'Request failed (HTTP ' + response.status + ').');
                }
                return json;
            });
        }
        function failBroken(error){
            status.textContent = ' ' + (error && error.message ? error.message : brokenText('brokenRequestFailed', 'Request failed. Reload the page and try again.'));
        }

        var selectAll = document.getElementById('ilsm-broken-all');
        if(selectAll){
            selectAll.addEventListener('change', function(){
                document.querySelectorAll('.ilsm-broken-item:not(:disabled)').forEach(function(item){ item.checked = selectAll.checked; });
            });
        }

        var checkNow = document.getElementById('ilsm-broken-check-now');
        if(checkNow){
            checkNow.addEventListener('click', function(){
                checkNow.disabled = true;
                status.textContent = ' ' + brokenText('brokenChecking', 'Checking…');
                postBroken({action:'ilsm_broken_monitor_run', nonce:ILSM.brokenNonce})
                    .then(function(result){ status.textContent = ' ' + result.data.message; window.setTimeout(function(){ window.location.reload(); }, 700); })
                    .catch(failBroken)
                    .finally(function(){ checkNow.disabled = false; });
            });
        }

        var action = document.getElementById('ilsm-broken-resolution');
        var urlWrap = document.getElementById('ilsm-broken-new-url-wrap');
        var codeWrap = document.getElementById('ilsm-broken-code-wrap');
        function syncBrokenControls(){
            if(!action){ return; }
            var unlink = action.value === 'unlink';
            var redirect = action.value.indexOf('redirect') !== -1;
            if(urlWrap){ urlWrap.hidden = unlink; }
            if(codeWrap){ codeWrap.hidden = !redirect; }
        }
        if(action){ action.addEventListener('change', syncBrokenControls); syncBrokenControls(); }

        var resolve = document.getElementById('ilsm-broken-resolve');
        if(resolve){
            resolve.addEventListener('click', function(){
                var ids = Array.prototype.slice.call(document.querySelectorAll('.ilsm-broken-item:checked')).map(function(item){ return item.value; }).slice(0,20);
                var mode = action ? action.value : '';
                var urlInput = document.getElementById('ilsm-broken-new-url');
                var codeInput = document.getElementById('ilsm-broken-code');
                var newUrl = urlInput ? urlInput.value.trim() : '';
                var code = codeInput ? codeInput.value : '301';
                if(!ids.length){ status.textContent = ' ' + brokenText('brokenSelectOne', 'Select at least one verified 404 link.'); return; }
                if(mode !== 'unlink' && !newUrl){ status.textContent = ' ' + brokenText('brokenEnterUrl', 'Enter the new destination URL.'); return; }
                var label = action && action.options[action.selectedIndex] ? action.options[action.selectedIndex].text : mode;
                if(!window.confirm(label + ' for ' + ids.length + ' ' + brokenText('brokenConfirmSuffix', 'selected link(s)? Content edits create revisions; redirects affect all requests to the old URL.'))){ return; }
                resolve.disabled = true;
                status.textContent = ' ' + brokenText('brokenApplying', 'Verifying and applying reviewed SEO repairs…');
                var data = {action:'ilsm_broken_bulk_resolve', nonce:ILSM.brokenNonce, resolution:mode, replacement_url:newUrl, redirect_code:code};
                ids.forEach(function(id, index){ data['link_ids[' + index + ']'] = id; });
                postBroken(data).then(function(result){
                    status.textContent = ' ' + result.data.message;
                    if(result.data.results){
                        Object.keys(result.data.results).forEach(function(id){
                            var resultEl = document.querySelector('[data-broken-result="' + id + '"]');
                            if(resultEl){ resultEl.textContent = result.data.results[id]; }
                        });
                    }
                    if(result.data.changed_ids){
                        result.data.changed_ids.forEach(function(id){
                            var resultEl = document.querySelector('[data-broken-result="' + id + '"]');
                            var row = resultEl ? resultEl.closest('tr') : null;
                            if(row){ row.remove(); }
                        });
                    }
                    if(result.data.reload){ window.setTimeout(function(){ window.location.reload(); }, 900); }
                }).catch(failBroken).finally(function(){ resolve.disabled = false; });
            });
        }
    }

    onReady(initBrokenLinkMaintenance);

    onReady(function(){
        document.addEventListener('click', function(ev){
            var refresh = ev.target.closest('.ilsm-refresh-opportunities');
            if(refresh){
                ev.preventDefault();
                window.location.reload();
                return;
            }
            var obsidian = ev.target.closest('.ilsm-visual-export-obsidian');
            if(obsidian && !updateObsidianExportLink(obsidian)){
                ev.preventDefault();
                return;
            }
            var button = ev.target.closest('[data-ilsm-export]');
            if(!button){ return; }
            ev.preventDefault();
            var action = button.getAttribute('data-ilsm-export');
            var target = button.getAttribute('data-ilsm-export-target') || (currentView() === 'knowledge-graph' ? 'knowledge' : 'architecture');
            if(action === 'png'){ exportPng(target, button); }
            if(action === 'pdf'){ exportPdf(target, button); }
        });
    });
})(jQuery);
