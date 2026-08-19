function ilsmMapT(key,fallback){return (window.ILSM&&ILSM.strings&&ILSM.strings[key])?ILSM.strings[key]:fallback;}
function ilsmMapFmt(key,fallback,value){return ilsmMapT(key,fallback).replace('%s',String(value));}
(function(){
'use strict';
function ready(fn){if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',fn);}else{fn();}}
function escAttr(v){return String(v==null?'':v).replace(/[&<>'\"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','\"':'&quot;'}[c];});}
ready(function(){
  var svg=document.getElementById('ilsm-wheel');
  if(!svg){return;}
  var wrap=svg.closest('.ilsm-svg-wrap');
  if(!wrap){return;}
  var minZoom=0.25,maxZoom=8,base=[0,0,900,520],view=base.slice(),dragging=false,last={x:0,y:0};
  function readView(){var raw=(svg.getAttribute('viewBox')||base.join(' ')).trim().split(/\s+/).map(Number);return raw.length===4&&raw.every(Number.isFinite)?raw:base.slice();}
  function setView(next){view=next;svg.setAttribute('viewBox',view.join(' '));updateLabel();}
  function zoomValue(){var b=base[2]||900;return Math.max(minZoom,Math.min(maxZoom,b/(view[2]||b)));}
  function updateLabel(){var el=document.getElementById('ilsm-zoom-level');if(el){el.textContent=Math.round(zoomValue()*100)+'%';}}
  function point(evt){var rect=svg.getBoundingClientRect();return{x:view[0]+((evt.clientX-rect.left)/Math.max(1,rect.width))*view[2],y:view[1]+((evt.clientY-rect.top)/Math.max(1,rect.height))*view[3]};}
  function zoomAt(factor,cx,cy){var current=zoomValue(),next=Math.max(minZoom,Math.min(maxZoom,current*factor)),effective=next/current;if(Math.abs(effective-1)<0.001){return;}var nw=view[2]/effective,nh=view[3]/effective;var rx=(cx-view[0])/view[2],ry=(cy-view[1])/view[3];setView([cx-rx*nw,cy-ry*nh,nw,nh]);}
  function fit(){window.setTimeout(function(){var box;try{box=svg.getBBox();}catch(e){box=null;}if(!box||!box.width||!box.height){base=[0,0,900,520];setView(base.slice());return;}var pad=Math.max(35,Math.min(90,Math.max(box.width,box.height)*0.08));base=[box.x-pad,box.y-pad,box.width+pad*2,box.height+pad*2];setView(base.slice());},30);}
  var controls=document.createElement('div');controls.className='ilsm-map-zoom-dock';controls.setAttribute('aria-label',ilsmMapT('mapZoomControls','Map zoom controls'));controls.innerHTML='<button type="button" data-map-action="in" title="'+escAttr(ilsmMapT('zoomIn','Zoom in'))+'" aria-label="'+escAttr(ilsmMapT('zoomIn','Zoom in'))+'"><i class="fa fa-plus"></i></button><button type="button" data-map-action="out" title="'+escAttr(ilsmMapT('zoomOut','Zoom out'))+'" aria-label="'+escAttr(ilsmMapT('zoomOut','Zoom out'))+'"><i class="fa fa-minus"></i></button><button type="button" data-map-action="fit" title="'+escAttr(ilsmMapT('fitMap','Fit map'))+'" aria-label="'+escAttr(ilsmMapT('fitMap','Fit map'))+'"><i class="fa fa-arrows-alt"></i></button><button type="button" data-map-action="reset" title="'+escAttr(ilsmMapT('resetZoom','Reset zoom'))+'" aria-label="'+escAttr(ilsmMapT('resetZoom','Reset zoom'))+'"><span id="ilsm-zoom-level">100%</span></button>';
  wrap.appendChild(controls);
  controls.addEventListener('click',function(e){var button=e.target.closest('button[data-map-action]');if(!button){return;}var action=button.getAttribute('data-map-action'),cx=view[0]+view[2]/2,cy=view[1]+view[3]/2;if(action==='in'){zoomAt(1.25,cx,cy);}else if(action==='out'){zoomAt(0.8,cx,cy);}else if(action==='fit'){fit();}else{setView(base.slice());}});
  svg.addEventListener('wheel',function(e){e.preventDefault();var p=point(e);zoomAt(e.deltaY<0?1.18:0.85,p.x,p.y);},{passive:false});
  svg.addEventListener('pointerdown',function(e){if(e.button!==0||e.target.closest('.ilsm-node')){return;}dragging=true;last={x:e.clientX,y:e.clientY};svg.setPointerCapture(e.pointerId);wrap.classList.add('is-panning');});
  svg.addEventListener('pointermove',function(e){if(!dragging){return;}var rect=svg.getBoundingClientRect(),dx=(e.clientX-last.x)*(view[2]/Math.max(1,rect.width)),dy=(e.clientY-last.y)*(view[3]/Math.max(1,rect.height));last={x:e.clientX,y:e.clientY};setView([view[0]-dx,view[1]-dy,view[2],view[3]]);});
  function stop(e){if(!dragging){return;}dragging=false;wrap.classList.remove('is-panning');try{svg.releasePointerCapture(e.pointerId);}catch(err){}}
  svg.addEventListener('pointerup',stop);svg.addEventListener('pointercancel',stop);
  var observer=new MutationObserver(function(){fit();});observer.observe(svg,{childList:true});
  var reset=document.getElementById('ilsm-reset-settings');if(reset){reset.addEventListener('click',function(){var form=reset.closest('form');if(!form){return;}[['batch_size','15'],['batch_delay','350'],['max_pages','5000'],['report_per_page','50'],['incoming_color','#2563EB'],['outgoing_color','#F97316'],['broken_color','#EF4444'],['redirect_color','#8B5CF6']].forEach(function(pair){var field=form.elements[pair[0]];if(field){field.value=pair[1];field.dispatchEvent(new Event('change',{bubbles:true}));}});Array.prototype.forEach.call(form.querySelectorAll('input[name="post_types[]"]'),function(cb){cb.checked=cb.getAttribute('data-ilsm-default')==='1';});var media=form.elements.exclude_media_links;if(media){media.checked=true;}var del=form.elements.delete_on_uninstall;if(del){del.checked=false;}});}
  view=readView();fit();
});
}());

(function(){
'use strict';
function ready(fn){if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',fn);}else{fn();}}
ready(function(){
  var panel=document.querySelector('[data-knowledge-graph]');
  if(!panel||typeof ILSM==='undefined'){return;}
  var canvas=panel.querySelector('.ilsm-knowledge-canvas'),ctx=canvas&&canvas.getContext?canvas.getContext('2d'):null,canvas3d=panel.querySelector('.ilsm-knowledge-canvas-3d'),ctx3d=canvas3d&&canvas3d.getContext?canvas3d.getContext('2d'):null,modeButtons=panel.querySelectorAll('.ilsm-knowledge-mode-btn');
  if(!ctx){return;}
  var state={data:null,nodes:[],edges:[],nodeMap:{},selected:0,hovered:0,scale:1,x:0,y:0,dragNode:null,panning:false,lastX:0,lastY:0,loaded:false,raf:0,signals:true,colorCache:{},mode:'2d',rotX:-0.28,rotY:0.45,zoom3d:1,pan3dX:0,pan3dY:0,drag3d:false,panGesture3d:false,last3dX:0,last3dY:0,dragNode3d:null,dragNode3dDepth:0,dragNode3dMoved:false,lastDataFingerprint:'',autoRefreshTimer:0,autoRefreshBusy:false,hoverEdgeKey:'',theme:'dark'};
  var search=panel.querySelector('.ilsm-knowledge-search'),depth=panel.querySelector('.ilsm-knowledge-depth'),limit=panel.querySelector('.ilsm-knowledge-limit'),keyboardNode=panel.querySelector('.ilsm-knowledge-node-selector'),live=panel.querySelector('.ilsm-knowledge-live'),signalToggle=panel.querySelector('.ilsm-knowledge-signals'),themeToggle=panel.querySelector('.ilsm-kg-theme-toggle'),typesCount=panel.querySelector('.ilsm-knowledge-types-count'),selectedLinksName=panel.querySelector('.ilsm-selected-links-name'),selectedLinksIncoming=panel.querySelector('.ilsm-selected-links-incoming'),selectedLinksOutgoing=panel.querySelector('.ilsm-selected-links-outgoing'),selectedLinksSearch=panel.querySelector('.ilsm-selected-links-search'),selectedLinksSort=panel.querySelector('.ilsm-selected-links-sort'),selectedLinksCountIn=panel.querySelector('.ilsm-selected-links-count-in'),selectedLinksCountOut=panel.querySelector('.ilsm-selected-links-count-out'),motionQuery=window.matchMedia?window.matchMedia('(prefers-reduced-motion: reduce)'):null,signalStorageKey='ilsm_knowledge_live_signals',selectedLinksPageId='',selectedLinksPageSize=10,selectedLinksLimits={incoming:10,outgoing:10};
  function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}

  var themeStorageKey='ilsm_knowledge_graph_theme';
  function themeIsLight(){return state.theme==='light';}
  function applyTheme(theme,save){
    state.theme=theme==='light'?'light':'dark';
    panel.setAttribute('data-theme',state.theme);
    var wrap=panel.closest('.ilsm-wrap');
    if(wrap){wrap.classList.toggle('ilsm-kg-theme-dark',state.theme==='dark');wrap.classList.toggle('ilsm-kg-theme-light',state.theme==='light');}
    if(themeToggle){
      var icon=themeToggle.querySelector('i'),label=themeToggle.querySelector('span'),dark=state.theme==='dark';
      themeToggle.setAttribute('aria-pressed',dark?'true':'false');
      themeToggle.setAttribute('title',dark?ilsmMapT('switchToLight','Switch to light mode'):ilsmMapT('switchToNight','Switch to night mode'));
      if(icon){icon.className='fa '+(dark?'fa-sun-o':'fa-moon-o');}
      if(label){label.textContent=dark?ilsmMapT('light','Light'):ilsmMapT('night','Night');}
    }
    state.colorCache={};
    if(save){try{window.localStorage.setItem(themeStorageKey,state.theme);}catch(err){}}
    render();
  }
  try{state.theme=window.localStorage.getItem('ilsm_global_admin_theme')||window.localStorage.getItem(themeStorageKey)||'dark';}catch(err){state.theme='light';}
  applyTheme(state.theme,false);
  if(themeToggle){themeToggle.addEventListener('click',function(){applyTheme(state.theme==='dark'?'light':'dark',true);});}


  document.addEventListener('ilsmThemeChanged',function(ev){
    var next=ev&&ev.detail&&ev.detail.theme?ev.detail.theme:'dark';
    state.theme=next==='light'?'light':'dark';
    panel.setAttribute('data-theme',state.theme);
    state.colorCache={};
    if(state.mode==='3d'){render3d();}else{render();}
  });

  function updateTypeCount(){
    if(!typesCount)return;
    typesCount.textContent=String(panel.querySelectorAll('.ilsm-knowledge-types input:checked').length);
  }
  updateTypeCount();
  Array.prototype.forEach.call(panel.querySelectorAll('.ilsm-knowledge-types input'),function(cb){cb.addEventListener('change',updateTypeCount);});

  function postTypes(){return Array.prototype.map.call(panel.querySelectorAll('.ilsm-knowledge-types input:checked'),function(el){return el.value;});}
  function request(){var body=new URLSearchParams();body.set('action','ilsm_architecture_data');body.set('nonce',ILSM.nonce);body.set('mode','knowledge');body.set('root_id','0');body.set('max_depth','0');body.set('status','all');body.set('min_in','0');body.set('min_out','0');postTypes().forEach(function(t){body.append('post_types[]',t);});return body;}
  function load(force){
    if(state.loaded&&!force){render();return;}
    panel.querySelector('.ilsm-knowledge-loading').hidden=false;canvas.setAttribute('aria-busy','true');
    fetch(ILSM.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:request().toString()}).then(function(r){return r.json();}).then(function(r){if(!r||!r.success){throw new Error(r&&r.data&&r.data.message?r.data.message:ilsmMapT('knowledgeLoadFailed','Knowledge graph could not be loaded.'));}state.data=r.data;state.loaded=true;build();}).catch(function(err){panel.querySelector('.ilsm-knowledge-empty').hidden=false;panel.querySelector('.ilsm-knowledge-empty').textContent=err.message||ilsmMapT('knowledgeRequestFailed','Knowledge graph request failed.');}).finally(function(){panel.querySelector('.ilsm-knowledge-loading').hidden=true;canvas.setAttribute('aria-busy','false');});
  }
  function syncKeyboardNodeOptions(){
    if(!keyboardNode)return;
    var selected=String(state.selected||''),placeholder=keyboardNode.options.length?keyboardNode.options[0].textContent:'';
    keyboardNode.textContent='';
    var first=document.createElement('option');first.value='';first.textContent=placeholder||'Choose a page…';keyboardNode.appendChild(first);
    state.nodes.slice().sort(function(a,b){return String(a.title||'').localeCompare(String(b.title||''));}).forEach(function(n){
      var option=document.createElement('option'),path=String(n.path||'');option.value=String(n.id);option.textContent=String(n.title||('Post #'+n.id))+(path?' — '+path:'');keyboardNode.appendChild(option);
    });
    keyboardNode.value=state.nodeMap[selected]?selected:'';
  }
  function hash(s){var h=2166136261,i;for(i=0;i<s.length;i++){h^=s.charCodeAt(i);h=Math.imul(h,16777619);}return h>>>0;}
  function groupAngle(type){return ((hash(type||'page')%360)/180)*Math.PI;}
  function build(){
    var raw=state.data||{nodes:[],edges:[]},max=parseInt((limit&&limit.value)||320,10),nodes=(raw.nodes||[]).slice();
    nodes.sort(function(a,b){var ao=(a.is_orphan?100000:0)+(a.authority_score||0),bo=(b.is_orphan?100000:0)+(b.authority_score||0);return bo-ao;});
    nodes=nodes.slice(0,max);var keep={};nodes.forEach(function(n){keep[n.id]=true;});
    var edges=(raw.edges||[]).filter(function(e){return keep[e.source]&&keep[e.target];}).slice(0,Math.max(1200,max*8));
    var W=1400,H=820,cx=W/2,cy=H/2;
    nodes.forEach(function(n,i){var a=groupAngle(n.post_type)+(i%17)*0.035,r=115+(i%31)*14+(hash(String(n.id))%80);n.x=cx+Math.cos(a)*r;n.y=cy+Math.sin(a)*r;n.vx=0;n.vy=0;});
    var map={};nodes.forEach(function(n){map[n.id]=n;});
    // Bounded force simulation: enough to reveal clusters without freezing large admin screens.
    var iterations=nodes.length>400?55:(nodes.length>220?75:95),iter,i,j,e,a,b,dx,dy,d,force;
    for(iter=0;iter<iterations;iter++){
      nodes.forEach(function(n){var ga=groupAngle(n.post_type),gx=cx+Math.cos(ga)*250,gy=cy+Math.sin(ga)*210;n.vx+=(gx-n.x)*0.0018;n.vy+=(gy-n.y)*0.0018;n.vx+=(cx-n.x)*0.00035;n.vy+=(cy-n.y)*0.00035;});
      // Local repulsion against a rotating sample keeps cost bounded.
      for(i=0;i<nodes.length;i++){a=nodes[i];for(j=1;j<=Math.min(18,nodes.length-1);j++){b=nodes[(i+j*17)%nodes.length];if(a===b)continue;dx=a.x-b.x;dy=a.y-b.y;d=Math.sqrt(dx*dx+dy*dy)||1;if(d<115){force=(115-d)*0.0025;a.vx+=dx/d*force;a.vy+=dy/d*force;}}}
      edges.forEach(function(edge){a=map[edge.source];b=map[edge.target];if(!a||!b)return;dx=b.x-a.x;dy=b.y-a.y;d=Math.sqrt(dx*dx+dy*dy)||1;force=(d-95)*0.0018*(1+Math.min(4,Number(edge.strength||1))*0.12);a.vx+=dx/d*force;a.vy+=dy/d*force;b.vx-=dx/d*force;b.vy-=dy/d*force;});
      nodes.forEach(function(n){n.vx*=0.78;n.vy*=0.78;n.x=Math.max(35,Math.min(W-35,n.x+n.vx));n.y=Math.max(35,Math.min(H-35,n.y+n.vy));});
    }
    state.nodes=nodes;state.edges=edges;
        state.lastDataFingerprint=graphFingerprint({nodes:state.nodes,edges:state.edges});state.nodeMap=map;state.hovered=0;state.colorCache={};syncKeyboardNodeOptions();
    // Default the Second Brain to a meaningful center: homepage when present,
    // otherwise the strongest internal-authority page in the visible graph.
    var defaultNode=null;
    nodes.forEach(function(n){
      var path=String(n.path||'').replace(/^\s+|\s+$/g,'');
      if(!defaultNode&&path==='/'){defaultNode=n;}
    });
    if(!defaultNode&&nodes.length){
      defaultNode=nodes.slice().sort(function(a,b){
        return Number(b.authority_score||0)-Number(a.authority_score||0)||Number(b.incoming_count||0)-Number(a.incoming_count||0);
      })[0];
    }
    state.selected=defaultNode?defaultNode.id:0;
    fit();updateMetrics();select(defaultNode);render();
    live.textContent=nodes.length+' pages · '+edges.length+' relationships';
  }
  function nodeRadius(n){var v=Number(n.authority_score||0);return Math.max(5,Math.min(19,5+Math.sqrt(Math.max(0,v))*1.15));}
  function colors(n){var key=n.is_orphan?'orphan':(Number(n.seo_score||0)<60?'weak':'type:'+String(n.post_type||'page'));if(state.colorCache[key])return state.colorCache[key];var styles=getComputedStyle(panel),value;if(n.is_orphan)value=styles.getPropertyValue('--ilsm-kg-orphan').trim()||'#ef4444';else if(Number(n.seo_score||0)<60)value=styles.getPropertyValue('--ilsm-kg-weak').trim()||'#f59e0b';else{var palette=['--ilsm-kg-1','--ilsm-kg-2','--ilsm-kg-3','--ilsm-kg-4','--ilsm-kg-5'];value=styles.getPropertyValue(palette[hash(n.post_type||'page')%palette.length]).trim()||'#5b8def';}state.colorCache[key]=value;return value;}
  function visibleSet(){var result={},q=((search&&search.value)||'').trim().toLowerCase(),selected=state.selected,hops=parseInt((depth&&depth.value)||0,10);state.nodes.forEach(function(n){if(!q||String(n.title||'').toLowerCase().indexOf(q)!==-1||String(n.path||'').toLowerCase().indexOf(q)!==-1)result[n.id]=true;});if(selected&&hops>0){var reached={};reached[selected]=0;for(var step=0;step<hops;step++){state.edges.forEach(function(e){if(reached[e.source]===step&&reached[e.target]===undefined)reached[e.target]=step+1;if(reached[e.target]===step&&reached[e.source]===undefined)reached[e.source]=step+1;});}Object.keys(result).forEach(function(id){if(reached[id]===undefined)delete result[id];});}return result;}
  function resize(){var rect=canvas.getBoundingClientRect(),dpr=Math.min(2,window.devicePixelRatio||1),w=Math.max(320,Math.round(rect.width)),h=Math.max(460,Math.round(rect.height));if(canvas.width!==Math.round(w*dpr)||canvas.height!==Math.round(h*dpr)){canvas.width=Math.round(w*dpr);canvas.height=Math.round(h*dpr);}ctx.setTransform(dpr,0,0,dpr,0,0);return {w:w,h:h,dpr:dpr};}
  function signalsOn(){return state.signals&&state.selected&&!document.hidden&&panel.classList.contains('is-active')&&!(motionQuery&&motionQuery.matches);}
  function signalEdges(visible){if(!state.selected)return[];var selected=state.selected,out=[];for(var i=0;i<state.edges.length&&out.length<24;i++){var e=state.edges[i],a=state.nodeMap[e.source],b=state.nodeMap[e.target];if(!a||!b||!visible[a.id]||!visible[b.id])continue;if(a.id===selected||b.id===selected)out.push(e);}return out;}
  function drawSignal(e,ts){
    var a=state.nodeMap[e.source],b=state.nodeMap[e.target];if(!a||!b)return;
    var incoming=state.selected&&String(e.target)===String(state.selected);
    var rgb=incoming?'52,211,153':'96,165,250';
    var strong=incoming?'16,185,129':'59,130,246';
    var phase=(hash(String(e.source)+':'+String(e.target))%1000)/1000,t=((ts*0.00038)+phase)%1,dx=b.x-a.x,dy=b.y-a.y,len=Math.sqrt(dx*dx+dy*dy)||1,ux=dx/len,uy=dy/len,from=nodeRadius(a)+2,to=Math.max(from+1,len-nodeRadius(b)-2),travel=Math.max(1,to-from),dist=from+travel*t,x=a.x+ux*dist,y=a.y+uy*dist;
    ctx.save();ctx.globalCompositeOperation='lighter';
    for(var tail=4;tail>=1;tail--){var td=Math.max(from,dist-tail*5),tx=a.x+ux*td,ty=a.y+uy*td;ctx.beginPath();ctx.arc(tx,ty,Math.max(1,3.8-tail*.55),0,Math.PI*2);ctx.fillStyle='rgba('+rgb+','+(0.055*(5-tail))+')';ctx.fill();}
    var glow=ctx.createRadialGradient(x,y,0,x,y,14);glow.addColorStop(0,'rgba(255,255,255,.98)');glow.addColorStop(.18,'rgba(255,255,255,.94)');glow.addColorStop(.45,'rgba('+rgb+',.68)');glow.addColorStop(1,'rgba('+strong+',0)');ctx.fillStyle=glow;ctx.beginPath();ctx.arc(x,y,14,0,Math.PI*2);ctx.fill();
    ctx.strokeStyle='rgba(255,255,255,.76)';ctx.lineWidth=.75;ctx.beginPath();ctx.moveTo(x-6,y);ctx.lineTo(x+6,y);ctx.moveTo(x,y-6);ctx.lineTo(x,y+6);ctx.stroke();
    if(t>.9){var pulse=(t-.9)/.1,pr=nodeRadius(b)+4+pulse*10;ctx.globalAlpha=(1-pulse)*.30;ctx.strokeStyle='rgba('+rgb+',1)';ctx.lineWidth=1.4;ctx.beginPath();ctx.arc(b.x,b.y,pr,0,Math.PI*2);ctx.stroke();ctx.globalAlpha=1;}
    ctx.restore();
  }
  function drawFrame(ts){
    state.raf=0;
    var sz=resize(),visible=visibleSet(),light=themeIsLight();
    ctx.clearRect(0,0,sz.w,sz.h);ctx.save();ctx.translate(state.x,state.y);ctx.scale(state.scale,state.scale);
    var selected=state.selected,activeSignals=signalsOn()?signalEdges(visible):[],neighborMap={},now=ts||performance.now();
    state.edges.forEach(function(e){
      var a=state.nodeMap[e.source],b=state.nodeMap[e.target];if(!a||!b||!visible[a.id]||!visible[b.id])return;
      var near=selected&&(a.id===selected||b.id===selected),hovered=state.hoverEdgeKey===edgeKey(e);
      if(near){neighborMap[a.id]=true;neighborMap[b.id]=true;}
      ctx.beginPath();ctx.moveTo(a.x,a.y);ctx.lineTo(b.x,b.y);
      ctx.strokeStyle=hovered?'rgba(250,204,21,.95)':(near?(light?'rgba(99,102,241,.55)':'rgba(139,92,246,.62)'):(light?'rgba(100,116,139,.20)':'rgba(148,163,184,.16)'));
      ctx.lineWidth=hovered?2.5:(near?1.65:Math.min(1.2,.45+Number(e.strength||1)*.12));ctx.stroke();
    });
    activeSignals.forEach(function(e){drawSignal(e,now);});
    var motionReduced=motionQuery&&motionQuery.matches,beatBase=(Math.sin(now*0.006)+1)/2,beat=motionReduced?0:Math.pow(beatBase,4);
    state.nodes.forEach(function(n){
      if(!visible[n.id])return;
      var r=nodeRadius(n),sel=String(n.id)===String(selected),near=!!neighborMap[n.id];
      ctx.globalAlpha=selected&&!sel&&!near?.25:1;
      if(sel){
        ctx.save();ctx.strokeStyle='rgba(250,204,21,'+(0.20+beat*.55)+')';ctx.lineWidth=1.6;
        ctx.beginPath();ctx.arc(n.x,n.y,r+8+(beat*8),0,Math.PI*2);ctx.stroke();ctx.restore();
      }
      ctx.beginPath();ctx.arc(n.x,n.y,r+(sel?3:0),0,Math.PI*2);
      ctx.fillStyle=sel?'#facc15':colors(n);ctx.shadowBlur=sel?18:0;ctx.shadowColor=sel?'#facc15':'transparent';ctx.fill();ctx.shadowBlur=0;
      ctx.globalAlpha=1;
      if(sel){ctx.strokeStyle=light?'#ca8a04':'#fde047';ctx.lineWidth=2.5;ctx.stroke();}
      if((sel||n.id===state.hovered||r>12)&&state.scale>.55){
        ctx.font='12px system-ui,-apple-system,sans-serif';ctx.fillStyle=light?'rgba(15,23,42,.94)':'rgba(241,245,249,.96)';ctx.textAlign='center';
        ctx.fillText(String(n.title||'').slice(0,34),n.x,n.y+r+16);
      }
    });
    ctx.restore();
    var needsHeartbeat=!!selected&&!motionReduced;
    if((activeSignals.length||needsHeartbeat)&&!state.raf){state.raf=requestAnimationFrame(drawFrame);}
  }
  function render(){if(state.mode==='3d'){render3d();return;}if(state.raf){cancelAnimationFrame(state.raf);state.raf=0;}state.raf=requestAnimationFrame(drawFrame);}
  function fit(){var rect=canvas.getBoundingClientRect(),w=Math.max(320,rect.width),h=Math.max(460,rect.height);state.scale=Math.min(w/1450,h/850)*.96;state.x=(w-1400*state.scale)/2;state.y=(h-820*state.scale)/2;render();}
  function graphPoint(ev){var rect=canvas.getBoundingClientRect();return{x:(ev.clientX-rect.left-state.x)/state.scale,y:(ev.clientY-rect.top-state.y)/state.scale};}
  function hit(ev){var p=graphPoint(ev),best=null,dist=1e9;state.nodes.forEach(function(n){var dx=n.x-p.x,dy=n.y-p.y,d=Math.sqrt(dx*dx+dy*dy);if(d<nodeRadius(n)+7&&d<dist){best=n;dist=d;}});return best;}
  function updateMetrics(){var m=state.data&&state.data.meta&&state.data.meta.totals?state.data.meta.totals:{};panel.querySelector('.ilsm-knowledge-metrics').innerHTML='<div class="ilsm-knowledge-stat"><span>'+esc(ilsmMapT('indexedPages','Indexed pages'))+'</span><strong>'+esc(m.total_pages||state.nodes.length)+'</strong></div><div class="ilsm-knowledge-stat"><span>'+esc(ilsmMapT('internalLinks','Internal links'))+'</span><strong>'+esc(m.total_internal_links||state.edges.length)+'</strong></div><div class="ilsm-knowledge-stat"><span>'+esc(ilsmMapT('orphanPages','Orphan pages'))+'</span><strong>'+esc(m.orphan_pages||0)+'</strong></div><div class="ilsm-knowledge-stat"><span>'+esc(ilsmMapT('architectureHealth','Architecture health'))+'</span><strong>'+esc(m.architecture_score||0)+'/100</strong></div>';}
  function select(n){renderSelectedLinks(n);state.selected=n?n.id:0;if(keyboardNode){keyboardNode.value=n?String(n.id):'';}render();var box=panel.querySelector('.ilsm-knowledge-details');if(!n){box.innerHTML='<p class="ilsm-muted">'+esc(ilsmMapT('selectNodeHelp','Select a node to see its relationships and SEO signals.'))+'</p>';return;}var incoming=state.edges.filter(function(e){return e.target===n.id;}).length,outgoing=state.edges.filter(function(e){return e.source===n.id;}).length;var map='admin.php?page=ilsm-visual-map&view=link-map&post_id='+encodeURIComponent(n.id),opps='admin.php?page=ilsm-link-opportunities&source_post_id='+encodeURIComponent(n.id);box.innerHTML='<span class="ilsm-badge '+(n.is_orphan?'is-warning':'is-success')+'">'+(n.is_orphan?ilsmMapT('orphan','Orphan'):ilsmMapT('connected','Connected'))+'</span><h3>'+esc(n.title)+'</h3><p class="ilsm-muted">'+esc(n.path||n.url)+'</p><div class="ilsm-knowledge-detail-grid"><span><small>'+esc(ilsmMapT('seoScore','SEO score'))+'</small><b>'+esc(n.seo_score||0)+'/100</b></span><span><small>'+esc(ilsmMapT('incoming','Incoming'))+'</small><b>'+esc(n.incoming_count||incoming)+'</b></span><span><small>'+esc(ilsmMapT('outgoing','Outgoing'))+'</small><b>'+esc(n.outgoing_count||outgoing)+'</b></span><span><small>'+esc(ilsmMapT('authority','Authority'))+'</small><b>'+esc(n.authority_score||0)+'</b></span></div><div class="ilsm-drawer-actions"><a class="ilsm-btn ilsm-btn-primary" href="'+esc(map)+'"><i class="fa fa-sitemap"></i> '+esc(ilsmMapT('pageInsights','Page Insights'))+'</a><a class="ilsm-btn" href="'+esc(opps)+'"><i class="fa fa-link"></i> '+esc(ilsmMapT('opportunities','Opportunities'))+'</a><a class="ilsm-btn" href="'+esc(n.url)+'" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> '+esc(ilsmMapT('viewPage','View page'))+'</a></div>';var exportPage=panel.querySelector('.ilsm-kg-export-card.is-page');if(exportPage){var base=exportPage.getAttribute('data-page-export-base')||'';exportPage.href=base+(base.indexOf('?')===-1?'?':'&')+'post_id='+encodeURIComponent(n.id);exportPage.classList.remove('is-disabled');exportPage.setAttribute('aria-disabled','false');var copy=exportPage.querySelector('.ilsm-kg-export-page-copy');if(copy){copy.textContent=ilsmMapFmt('exportPageRelationships','Export %s with every scanned incoming and outgoing relationship for this completed scan.',n.title);}}var obsidian=panel.querySelector('.ilsm-visual-export-obsidian');if(obsidian){var obase=obsidian.getAttribute('data-ilsm-obsidian-base')||obsidian.href||'';try{var ourl=new URL(obase,window.location.href);ourl.searchParams.set('post_id',n.id);obsidian.href=ourl.toString();obsidian.setAttribute('data-ilsm-selected-post-id',n.id);}catch(e){}}live.textContent=ilsmMapFmt('selectedPage','Selected %s',n.title);}
  if(keyboardNode){keyboardNode.addEventListener('change',function(){var id=String(keyboardNode.value||''),n=state.nodeMap[id]||state.nodeMap[Number(id)]||null;if(n){select(n);centerSelectedNode(n);}else{select(null);}});}
  canvas.addEventListener('pointerdown',function(ev){var n=hit(ev);state.lastX=ev.clientX;state.lastY=ev.clientY;if(n){state.dragNode=n;select(n);}else{state.panning=true;}try{canvas.setPointerCapture(ev.pointerId);}catch(e){}});
  canvas.addEventListener('pointermove',function(ev){var n=hit(ev);state.hovered=n?n.id:0;canvas.style.cursor=state.dragNode?'grabbing':(n?'pointer':(state.panning?'grabbing':'grab'));if(state.dragNode){var p=graphPoint(ev);state.dragNode.x=p.x;state.dragNode.y=p.y;render();}else if(state.panning){state.x+=ev.clientX-state.lastX;state.y+=ev.clientY-state.lastY;state.lastX=ev.clientX;state.lastY=ev.clientY;render();}else{render();}});
  function stop(ev){state.dragNode=null;state.panning=false;try{canvas.releasePointerCapture(ev.pointerId);}catch(e){}}canvas.addEventListener('pointerup',stop);canvas.addEventListener('pointercancel',stop);
  canvas.addEventListener('dblclick',function(ev){var n=hit(ev);if(n){window.location.href='admin.php?page=ilsm-visual-map&view=link-map&post_id='+encodeURIComponent(n.id);}});
  canvas.addEventListener('wheel',function(ev){ev.preventDefault();var rect=canvas.getBoundingClientRect(),mx=ev.clientX-rect.left,my=ev.clientY-rect.top,old=state.scale,next=Math.max(.18,Math.min(3.2,old*(ev.deltaY<0?1.12:.89)));state.x=mx-(mx-state.x)*(next/old);state.y=my-(my-state.y)*(next/old);state.scale=next;render();},{passive:false});
  function zoom(f){var rect=canvas.getBoundingClientRect(),mx=rect.width/2,my=rect.height/2,old=state.scale,next=Math.max(.18,Math.min(3.2,old*f));state.x=mx-(mx-state.x)*(next/old);state.y=my-(my-state.y)*(next/old);state.scale=next;render();}
  panel.querySelector('.ilsm-knowledge-zoom-in').addEventListener('click',function(){
    if(state.mode==='3d'){state.zoom3d=Math.min(5,state.zoom3d*1.16);render3d();live.textContent='3D zoom: '+Math.round(state.zoom3d*100)+'%.';}else{zoom(1.18);}
  });
  panel.querySelector('.ilsm-knowledge-zoom-out').addEventListener('click',function(){
    if(state.mode==='3d'){state.zoom3d=Math.max(.25,state.zoom3d/1.16);render3d();live.textContent='3D zoom: '+Math.round(state.zoom3d*100)+'%.';}else{zoom(.84);}
  });
  panel.querySelector('.ilsm-knowledge-fit').addEventListener('click',function(){
    if(state.mode==='3d'){fit3d();}else{fit();}
  });
  panel.querySelector('.ilsm-knowledge-reset').addEventListener('click',function(){
    state.selected=0;if(search){search.value='';}if(depth){depth.value='1';}
    if(state.mode==='3d'){state.rotX=-0.28;state.rotY=0.45;state.zoom3d=1;state.pan3dX=0;state.pan3dY=0;render3d();}else{fit();}
    select(null);
  });panel.querySelector('.ilsm-load-knowledge').addEventListener('click',function(){var menu=panel.querySelector('.ilsm-knowledge-types-menu');if(menu){menu.open=false;}state.loaded=false;load(true);});
  var exportModal=panel.querySelector('.ilsm-kg-export-modal'),exportOpen=panel.querySelector('.ilsm-kg-export-open'),exportLastFocus=null;function closeExport(){if(!exportModal)return;exportModal.hidden=true;exportModal.setAttribute('aria-hidden','true');document.body.classList.remove('ilsm-modal-open');if(exportLastFocus&&exportLastFocus.focus){exportLastFocus.focus();}}if(exportOpen&&exportModal){exportOpen.addEventListener('click',function(){exportLastFocus=exportOpen;exportModal.hidden=false;exportModal.setAttribute('aria-hidden','false');document.body.classList.add('ilsm-modal-open');var first=exportModal.querySelector('.ilsm-kg-export-close');if(first)first.focus();});Array.prototype.forEach.call(exportModal.querySelectorAll('[data-export-close]'),function(el){el.addEventListener('click',closeExport);});exportModal.addEventListener('click',function(e){var card=e.target.closest('.ilsm-kg-export-card.is-disabled');if(card){e.preventDefault();live.textContent=ilsmMapT('selectNodeExport','Select a page node before exporting a page report.');}});document.addEventListener('keydown',function(e){if(exportModal.hidden){return;}if(e.key==='Escape'){e.preventDefault();closeExport();return;}if(e.key==='Tab'){var items=Array.prototype.filter.call(exportModal.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'),function(el){return !el.hidden&&el.offsetParent!==null;});if(!items.length){e.preventDefault();return;}var firstItem=items[0],lastItem=items[items.length-1];if(e.shiftKey&&document.activeElement===firstItem){e.preventDefault();lastItem.focus();}else if(!e.shiftKey&&document.activeElement===lastItem){e.preventDefault();firstItem.focus();}}});}
  if(signalToggle){
    var signalLabel=signalToggle.querySelector('.ilsm-knowledge-signals-label'),savedSignals=null;
    try{savedSignals=window.localStorage.getItem(signalStorageKey);}catch(storageError){}
    if(savedSignals==='off'){state.signals=false;}
    if(savedSignals==='on'){state.signals=true;}
    if(motionQuery&&motionQuery.matches&&savedSignals===null){state.signals=false;}
    function syncSignalToggle(){
      signalToggle.classList.toggle('is-active',state.signals);
      signalToggle.setAttribute('aria-pressed',state.signals?'true':'false');
      if(signalLabel){signalLabel.textContent=state.signals?ilsmMapT('liveSignalsOn','Live signals: ON'):ilsmMapT('liveSignalsOff','Live signals: OFF');}
    }
    syncSignalToggle();
    signalToggle.addEventListener('click',function(){
      state.signals=!state.signals;
      try{window.localStorage.setItem(signalStorageKey,state.signals?'on':'off');}catch(storageError){}
      syncSignalToggle();
      live.textContent=state.signals?ilsmMapT('liveLinkSignalsOn','Live link signals on'):ilsmMapT('liveLinkSignalsOff','Live link signals off');
      if(!state.signals&&state.raf){cancelAnimationFrame(state.raf);state.raf=0;}
      render();
    });
  }
  [search,depth].forEach(function(el){if(!el)return;el.addEventListener('input',render);el.addEventListener('change',render);});if(limit){limit.addEventListener('change',function(){if(state.data)build();});}Array.prototype.forEach.call(panel.querySelectorAll('.ilsm-knowledge-types input'),function(el){el.addEventListener('change',function(){state.loaded=false;load(true);});});
  document.addEventListener('click',function(ev){var tab=ev.target.closest('[data-map-tab="knowledge-graph"]');if(tab){window.setTimeout(function(){load(false);fit();},30);}});
  window.addEventListener('resize',function(){if(panel.classList.contains('is-active'))fit();});
  document.addEventListener('visibilitychange',function(){if(document.hidden&&state.raf){cancelAnimationFrame(state.raf);state.raf=0;}else if(panel.classList.contains('is-active')){render();}});
  if(motionQuery&&motionQuery.addEventListener){motionQuery.addEventListener('change',function(){if(motionQuery.matches){if(state.raf){cancelAnimationFrame(state.raf);state.raf=0;}}render();});}

  function edgeKey(e){return String(e.source)+':'+String(e.target);}
  function centerSelectedNode(n){
    if(!n)return;
    if(state.mode==='3d'){
      var w=canvas3d.clientWidth||900,h=canvas3d.clientHeight||620,q=project3d(n,w,h);
      state.pan3dX+=w/2-q.x;state.pan3dY+=h/2-q.y;render3d();
    }else{
      var rect=canvas.getBoundingClientRect(),w2=rect.width||900,h2=rect.height||620;
      state.x=(w2/2)-(n.x*state.scale);state.y=(h2/2)-(n.y*state.scale);render();
    }
  }
  function relatedEdges(node,direction){
    if(!node)return [];
    return state.edges.filter(function(e){
      return direction==='incoming'?String(e.target)===String(node.id):String(e.source)===String(node.id);
    });
  }
  function sortConnections(items,direction){
    var mode=selectedLinksSort?selectedLinksSort.value:'authority';
    return items.slice().sort(function(a,b){
      var aid=direction==='incoming'?a.source:a.target,bid=direction==='incoming'?b.source:b.target;
      var an=state.nodeMap[String(aid)]||state.nodeMap[aid],bn=state.nodeMap[String(bid)]||state.nodeMap[bid];
      if(!an||!bn)return 0;
      if(mode==='title')return String(an.title||'').localeCompare(String(bn.title||''));
      if(mode==='seo')return Number(bn.seo_score||0)-Number(an.seo_score||0);
      return Number(bn.authority_score||0)-Number(an.authority_score||0);
    });
  }
  function connectionRows(node,direction){
    var q=((selectedLinksSearch&&selectedLinksSearch.value)||'').trim().toLowerCase();
    var rows=sortConnections(relatedEdges(node,direction),direction).filter(function(e){
      var otherId=direction==='incoming'?e.source:e.target,other=state.nodeMap[String(otherId)]||state.nodeMap[otherId];
      if(!other)return false;
      if(!q)return true;
      return String(other.title||'').toLowerCase().indexOf(q)!==-1||String(e.anchor||'').toLowerCase().indexOf(q)!==-1;
    });
    if(!rows.length)return '<p class="ilsm-selected-links-empty">'+esc(ilsmMapFmt('noDirectionLinks','No %s links match this view.',direction))+'</p>';
    var visibleLimit=selectedLinksLimits[direction]||selectedLinksPageSize;
    var markup=rows.slice(0,visibleLimit).map(function(e){
      var otherId=direction==='incoming'?e.source:e.target,other=state.nodeMap[String(otherId)]||state.nodeMap[otherId];
      if(!other)return '';
      var title=other.title||other.path||ilsmMapFmt('pageNumber','Page #%s',other.id),anchor=e.anchor||ilsmMapT('noAnchorRecorded','No anchor text recorded'),score=Number(other.authority_score||0);
      return '<div class="ilsm-selected-link-row" data-edge-key="'+esc(edgeKey(e))+'" data-node-id="'+esc(other.id)+'">'+
        '<button type="button" class="ilsm-selected-link-page" title="'+esc(title)+'">'+esc(title)+'</button>'+
        '<div class="ilsm-selected-link-anchor">'+esc(ilsmMapT('anchorText','Anchor text'))+': <strong>'+esc(anchor)+'</strong></div>'+
        '<span class="ilsm-selected-link-score" title="'+esc(ilsmMapT('internalAuthority','Internal authority'))+'">'+esc(score)+'</span>'+
      '</div>';
    }).join('');
    if(rows.length>visibleLimit){
      var remaining=rows.length-visibleLimit,nextCount=Math.min(selectedLinksPageSize,remaining);
      markup+='<button type="button" class="ilsm-selected-links-load-more" data-direction="'+esc(direction)+'" aria-label="'+esc(ilsmMapFmt('connectionsRemaining','%s connections remaining',remaining))+'">'+esc(ilsmMapFmt('loadMoreConnections','Load %s more',nextCount))+' <span aria-hidden="true">('+esc(remaining)+')</span></button>';
    }
    return markup;
  }
  function bindConnectionRows(container){
    if(!container)return;
    Array.prototype.forEach.call(container.querySelectorAll('.ilsm-selected-link-row'),function(row){
      var id=row.getAttribute('data-node-id'),key=row.getAttribute('data-edge-key'),btn=row.querySelector('.ilsm-selected-link-page');
      if(btn){btn.addEventListener('click',function(){var target=state.nodeMap[String(id)]||state.nodeMap[id];if(target){select(target);centerSelectedNode(target);}});}
      row.addEventListener('mouseenter',function(){state.hoverEdgeKey=key;row.classList.add('is-hovered');render();});
      row.addEventListener('mouseleave',function(){state.hoverEdgeKey='';row.classList.remove('is-hovered');render();});
    });
    var more=container.querySelector('.ilsm-selected-links-load-more');
    if(more){more.addEventListener('click',function(){var direction=more.getAttribute('data-direction');if(direction!=='incoming'&&direction!=='outgoing'){return;}selectedLinksLimits[direction]+=selectedLinksPageSize;renderSelectedLinks(state.nodeMap[String(state.selected)]||state.nodeMap[state.selected]||null);var next=(direction==='incoming'?selectedLinksIncoming:selectedLinksOutgoing).querySelector('.ilsm-selected-links-load-more');if(next){next.focus();}});}
  }
  function renderSelectedLinks(node){
    if(!selectedLinksIncoming||!selectedLinksOutgoing)return;
    if(!node){
      if(selectedLinksName)selectedLinksName.textContent=ilsmMapT('noPageSelected','No page selected');
      if(selectedLinksCountIn)selectedLinksCountIn.textContent='0';
      if(selectedLinksCountOut)selectedLinksCountOut.textContent='0';
      selectedLinksIncoming.innerHTML='<p class="ilsm-selected-links-empty">'+esc(ilsmMapT('selectIncomingHelp','Select a node in the graph to see incoming links.'))+'</p>';
      selectedLinksOutgoing.innerHTML='<p class="ilsm-selected-links-empty">'+esc(ilsmMapT('selectOutgoingHelp','Select a node in the graph to see outgoing links.'))+'</p>';
      selectedLinksPageId='';selectedLinksLimits={incoming:selectedLinksPageSize,outgoing:selectedLinksPageSize};
      return;
    }
    if(String(node.id)!==selectedLinksPageId){selectedLinksPageId=String(node.id);selectedLinksLimits={incoming:selectedLinksPageSize,outgoing:selectedLinksPageSize};}
    var incoming=relatedEdges(node,'incoming'),outgoing=relatedEdges(node,'outgoing');
    if(selectedLinksName)selectedLinksName.textContent=node.title||node.path||ilsmMapFmt('pageNumber','Page #%s',node.id);
    if(selectedLinksCountIn)selectedLinksCountIn.textContent=String(incoming.length);
    if(selectedLinksCountOut)selectedLinksCountOut.textContent=String(outgoing.length);
    selectedLinksIncoming.innerHTML=connectionRows(node,'incoming');
    selectedLinksOutgoing.innerHTML=connectionRows(node,'outgoing');
    bindConnectionRows(selectedLinksIncoming);bindConnectionRows(selectedLinksOutgoing);
  }
  [selectedLinksSearch,selectedLinksSort].forEach(function(ctrl){
    if(!ctrl)return;
    ctrl.addEventListener(ctrl===selectedLinksSearch?'input':'change',function(){
      selectedLinksLimits={incoming:selectedLinksPageSize,outgoing:selectedLinksPageSize};
      renderSelectedLinks(state.nodeMap[String(state.selected)]||state.nodeMap[state.selected]||null);
    });
  });

  function graphFingerprint(payload){
    try{
      var nodes=(payload&&payload.nodes)||[],edges=(payload&&payload.edges)||[];
      var tailNode=nodes.length?nodes[nodes.length-1]:{};
      var tailEdge=edges.length?edges[edges.length-1]:{};
      return [nodes.length,edges.length,tailNode.id||'',tailNode.modified||'',tailEdge.source||'',tailEdge.target||''].join('|');
    }catch(err){return '';}
  }
  function seed3d(){state.nodes.forEach(function(n,i){if(typeof n.z3d!=='number'){var h=((parseInt(n.id,10)||i)*2654435761)>>>0;n.z3d=(((h%1000)/1000)-.5)*520;}});}
  function project3d(n,w,h){var x=(n.x||0)-700,y=(n.y||0)-410,z=n.z3d||0,cy=Math.cos(state.rotY),sy=Math.sin(state.rotY),cx=Math.cos(state.rotX),sx=Math.sin(state.rotX),x1=x*cy-z*sy,z1=x*sy+z*cy,y1=y*cx-z1*sx,z2=y*sx+z1*cx,p=900/(1150+z2);return{x:w/2+x1*p*state.zoom3d+state.pan3dX,y:h/2+y1*p*state.zoom3d+state.pan3dY,z:z2,p:p};}

  function screenToLocal3d(clientX,clientY){
    var rect=canvas3d.getBoundingClientRect();
    return {x:clientX-rect.left,y:clientY-rect.top,w:Math.max(320,rect.width),h:Math.max(460,rect.height)};
  }
  function pickNode3d(clientX,clientY){
    if(!canvas3d)return null;
    seed3d();
    var m=screenToLocal3d(clientX,clientY),best=null,bestD=Infinity,vis=visibleSet();
    state.nodes.forEach(function(n){
      if(!vis[n.id])return;
      var q=project3d(n,m.w,m.h),sel=n.id===state.selected;
      var radius=Math.max(9,Math.min(18,(sel?13:10)*Math.max(.75,q.p)));
      var dx=m.x-q.x,dy=m.y-q.y,d=Math.sqrt(dx*dx+dy*dy);
      if(d<=radius&&d<bestD){best={node:n,projected:q};bestD=d;}
    });
    return best;
  }
  function setNodeFromScreen3d(n,clientX,clientY,depth){
    var m=screenToLocal3d(clientX,clientY);
    var cy=Math.cos(state.rotY),sy=Math.sin(state.rotY),cx=Math.cos(state.rotX),sx=Math.sin(state.rotX);
    var z2=depth||0,p=900/(1150+z2);
    if(!isFinite(p)||Math.abs(p)<0.0001)return;
    var x1=((m.x-m.w/2-state.pan3dX)/(p*state.zoom3d));
    var y1=((m.y-m.h/2-state.pan3dY)/(p*state.zoom3d));
    // Inverse X rotation while preserving the picked node's camera-space depth.
    var y=y1*cx+z2*sx;
    var z1=-y1*sx+z2*cx;
    // Inverse Y rotation.
    var x=x1*cy+z1*sy;
    var z=-x1*sy+z1*cy;
    n.x=x+700;
    n.y=y+410;
    n.z3d=z;
    n.pinned3d=true;
  }

  function projectPoint3d(x,y,z,w,h){
    return project3d({x:x,y:y,z3d:z},w,h);
  }
  function drawSignal3d(e,ts,w,h){
    var a=state.nodeMap[e.source],b=state.nodeMap[e.target];
    if(!a||!b)return;
    var incoming=state.selected&&String(e.target)===String(state.selected);
    var outgoing=state.selected&&String(e.source)===String(state.selected);
    if(!incoming&&!outgoing)return;

    var phase=(hash(String(e.source)+':'+String(e.target))%1000)/1000;
    var t=((ts*0.00038)+phase)%1;

    var ax=a.x||0,ay=a.y||0,az=a.z3d||0;
    var bx=b.x||0,by=b.y||0,bz=b.z3d||0;
    var x=ax+(bx-ax)*t,y=ay+(by-ay)*t,z=az+(bz-az)*t;
    var p=projectPoint3d(x,y,z,w,h);

    var rgb=incoming?'52,211,153':'167,139,250';
    var rgbStrong=incoming?'16,185,129':'124,58,237';

    ctx3d.save();
    ctx3d.globalCompositeOperation='lighter';

    for(var k=1;k<=4;k++){
      var tt=Math.max(0,t-k*0.018);
      var tx=ax+(bx-ax)*tt,ty=ay+(by-ay)*tt,tz=az+(bz-az)*tt;
      var tp=projectPoint3d(tx,ty,tz,w,h);
      ctx3d.beginPath();
      ctx3d.arc(tp.x,tp.y,Math.max(1,3.5-k*.45),0,Math.PI*2);
      ctx3d.fillStyle='rgba('+rgb+','+(0.16-(k*.024))+')';
      ctx3d.fill();
    }

    var radius=Math.max(10,14*Math.max(.72,p.p));
    var glow=ctx3d.createRadialGradient(p.x,p.y,0,p.x,p.y,radius);
    glow.addColorStop(0,'rgba(255,255,255,.99)');
    glow.addColorStop(.15,'rgba(255,255,255,.94)');
    glow.addColorStop(.42,'rgba('+rgb+',.78)');
    glow.addColorStop(.74,'rgba('+rgbStrong+',.25)');
    glow.addColorStop(1,'rgba('+rgbStrong+',0)');
    ctx3d.fillStyle=glow;
    ctx3d.beginPath();ctx3d.arc(p.x,p.y,radius,0,Math.PI*2);ctx3d.fill();

    ctx3d.strokeStyle='rgba(255,255,255,.82)';
    ctx3d.lineWidth=.8;
    ctx3d.beginPath();
    ctx3d.moveTo(p.x-6,p.y);ctx3d.lineTo(p.x+6,p.y);
    ctx3d.moveTo(p.x,p.y-6);ctx3d.lineTo(p.x,p.y+6);
    ctx3d.stroke();

    if(t>.90){
      var bp=project3d(b,w,h),pulse=(t-.90)/.10,pr=8+pulse*14;
      ctx3d.globalAlpha=(1-pulse)*.46;
      ctx3d.strokeStyle='rgba('+rgb+',1)';
      ctx3d.lineWidth=1.5;
      ctx3d.beginPath();ctx3d.arc(bp.x,bp.y,pr,0,Math.PI*2);ctx3d.stroke();
      ctx3d.globalAlpha=1;
    }
    ctx3d.restore();
  }

  function render3d(ts){
    if(state.mode!=='3d'||!ctx3d)return;
    if(typeof ts==='number'){state.raf=0;}
    seed3d();
    var dpr=Math.min(2,window.devicePixelRatio||1),rect=canvas3d.getBoundingClientRect(),w=Math.max(320,rect.width),h=Math.max(460,rect.height);
    var targetW=Math.round(w*dpr),targetH=Math.round(h*dpr);
    if(canvas3d.width!==targetW||canvas3d.height!==targetH){canvas3d.width=targetW;canvas3d.height=targetH;}
    ctx3d.setTransform(dpr,0,0,dpr,0,0);
    ctx3d.fillStyle=themeIsLight()?'#f8fafc':'#09101f';ctx3d.fillRect(0,0,w,h);
    var vis=visibleSet(),activeSignals=signalsOn()?signalEdges(vis):[];

    state.edges.forEach(function(e){
      var a=state.nodeMap[e.source],b=state.nodeMap[e.target];
      if(!a||!b||!vis[a.id]||!vis[b.id])return;
      var pa=project3d(a,w,h),pb=project3d(b,w,h),near=state.selected&&(a.id===state.selected||b.id===state.selected);
      ctx3d.beginPath();ctx3d.moveTo(pa.x,pa.y);ctx3d.lineTo(pb.x,pb.y);
      ctx3d.strokeStyle=near?'rgba(167,139,250,.62)':'rgba(148,163,184,.20)';
      ctx3d.lineWidth=near?1.5:.7;ctx3d.stroke();
    });

    if(activeSignals.length){
      var now=typeof ts==='number'?ts:performance.now();
      activeSignals.forEach(function(e){drawSignal3d(e,now,w,h);});
    }

    var motionReduced=motionQuery&&motionQuery.matches;
    var beatTime=typeof ts==='number'?ts:performance.now();
    var beatBase=(Math.sin(beatTime*0.006)+1)/2;
    var beat=motionReduced?0:Math.pow(beatBase,4);
    state.nodes.filter(function(n){return vis[n.id];}).slice().sort(function(a,b){
      return project3d(a,w,h).z-project3d(b,w,h).z;
    }).forEach(function(n){
      var q=project3d(n,w,h),sel=String(n.id)===String(state.selected),r=Math.max(3,Math.min(12,(sel?9:5)*Math.max(.7,q.p)));
      if(sel){
        ctx3d.save();
        ctx3d.strokeStyle='rgba(250,204,21,'+(0.18+beat*.52)+')';
        ctx3d.lineWidth=1.5;
        ctx3d.beginPath();
        ctx3d.arc(q.x,q.y,r+5+(beat*7),0,Math.PI*2);
        ctx3d.stroke();
        ctx3d.restore();
      }
      ctx3d.beginPath();ctx3d.arc(q.x,q.y,r,0,Math.PI*2);
      ctx3d.fillStyle=sel?'#facc15':colors(n);
      ctx3d.shadowBlur=sel?20:7;ctx3d.shadowColor=ctx3d.fillStyle;ctx3d.fill();ctx3d.shadowBlur=0;
    });

    var needsHeartbeat=!!state.selected&&!(motionQuery&&motionQuery.matches);
    if((activeSignals.length||needsHeartbeat)&&!state.raf){
      state.raf=requestAnimationFrame(render3d);
    }
  }

  function fit3d(){
    if(!canvas3d)return;
    seed3d();
    var w=canvas3d.clientWidth||900,h=canvas3d.clientHeight||620,vis=visibleSet();
    state.pan3dX=0;state.pan3dY=0;state.zoom3d=1;
    var nodes=state.nodes.filter(function(n){return vis[n.id];});
    if(!nodes.length){render3d();return;}
    var pts=nodes.map(function(n){return project3d(n,w,h);}),minX=Infinity,maxX=-Infinity,minY=Infinity,maxY=-Infinity;
    pts.forEach(function(p){minX=Math.min(minX,p.x);maxX=Math.max(maxX,p.x);minY=Math.min(minY,p.y);maxY=Math.max(maxY,p.y);});
    var graphW=Math.max(1,maxX-minX),graphH=Math.max(1,maxY-minY);
    state.zoom3d=Math.max(.28,Math.min(3.5,Math.min((w*.82)/graphW,(h*.82)/graphH)));
    pts=nodes.map(function(n){return project3d(n,w,h);});minX=Infinity;maxX=-Infinity;minY=Infinity;maxY=-Infinity;
    pts.forEach(function(p){minX=Math.min(minX,p.x);maxX=Math.max(maxX,p.x);minY=Math.min(minY,p.y);maxY=Math.max(maxY,p.y);});
    state.pan3dX+=(w/2)-((minX+maxX)/2);state.pan3dY+=(h/2)-((minY+maxY)/2);
    render3d();live.textContent=ilsmMapT('graphFitted','3D graph fitted to view.');
  }

  function setGraphMode(mode){if(state.raf){cancelAnimationFrame(state.raf);state.raf=0;}state.mode=mode==='3d'?'3d':'2d';canvas.hidden=state.mode==='3d';canvas3d.hidden=state.mode!=='3d';modeButtons.forEach(function(btn){var on=btn.getAttribute('data-mode')===state.mode;btn.classList.toggle('is-active',on);btn.setAttribute('aria-pressed',on?'true':'false');});if(state.mode==='3d'){render3d();live.textContent='3D: drag to rotate, wheel to zoom, Shift+drag to pan.';}else{render();live.textContent='2D Knowledge Graph active.';}}
  
  function logicalControlRefresh(){
    applyFilters();
  }
  function applyFilters(){if(state.mode==='3d'){render3d();}else{render();}}
  [depth,limit].forEach(function(ctrl){
    if(ctrl){ctrl.addEventListener('change',logicalControlRefresh);}
  });

  modeButtons.forEach(function(btn){btn.addEventListener('click',function(){setGraphMode(btn.getAttribute('data-mode'));});});
  if(canvas3d){
    canvas3d.addEventListener('contextmenu',function(e){e.preventDefault();});

    canvas3d.addEventListener('pointerdown',function(e){
      if(e.button!==0&&e.button!==1){return;}
      e.preventDefault();
      var picked=pickNode3d(e.clientX,e.clientY);
      state.last3dX=e.clientX;state.last3dY=e.clientY;state.dragNode3dMoved=false;

      if(e.shiftKey||e.button===1){
        state.drag3d=true;state.dragNode3d=null;state.panGesture3d=true;
        canvas3d.classList.add('is-dragging');
      }else if(picked){
        state.dragNode3d=picked.node;state.dragNode3dDepth=picked.projected.z;state.drag3d=false;state.panGesture3d=false;
        select(picked.node);canvas3d.classList.add('is-dragging-node');
        live.textContent=ilsmMapT('nodeSelectedDrag','Node selected. Drag to reposition it; Alt/Option + drag changes depth.');
      }else{
        state.drag3d=true;state.dragNode3d=null;state.panGesture3d=false;
        canvas3d.classList.add('is-dragging');live.textContent=ilsmMapT('rotate3d','Rotate 3D graph.');
      }
      try{canvas3d.setPointerCapture(e.pointerId);}catch(err){}
      render3d();
    });

    canvas3d.addEventListener('pointermove',function(e){
      if(!state.drag3d&&!state.dragNode3d){return;}
      e.preventDefault();
      var dx=e.clientX-state.last3dX,dy=e.clientY-state.last3dY;
      state.last3dX=e.clientX;state.last3dY=e.clientY;

      if(state.dragNode3d){
        if(Math.abs(dx)+Math.abs(dy)>1){state.dragNode3dMoved=true;}
        if(e.altKey){
          state.dragNode3d.z3d=(state.dragNode3d.z3d||0)+dy*2.5;
          var rr=canvas3d.getBoundingClientRect();
          state.dragNode3dDepth=project3d(state.dragNode3d,rr.width||900,rr.height||620).z;
        }else{
          setNodeFromScreen3d(state.dragNode3d,e.clientX,e.clientY,state.dragNode3dDepth);
        }
        render3d();return;
      }

      if(state.panGesture3d||e.shiftKey){
        state.pan3dX+=dx;state.pan3dY+=dy;state.panGesture3d=true;
      }else{
        state.rotY+=dx*.009;
        state.rotX=Math.max(-1.45,Math.min(1.45,state.rotX+dy*.009));
      }
      render3d();
    });

    function stop3d(e){
      if(state.dragNode3d){live.textContent=state.dragNode3dMoved?ilsmMapT('nodeRepositioned3d','Node repositioned in the 3D view.'):ilsmMapT('nodeSelected','Node selected.');}
      else if(state.drag3d){live.textContent=state.panGesture3d?ilsmMapT('graphPanned','3D graph panned.'):ilsmMapT('graphRotated','3D graph rotated.');}
      state.dragNode3d=null;state.dragNode3dMoved=false;state.drag3d=false;state.panGesture3d=false;
      canvas3d.classList.remove('is-dragging','is-dragging-node');
      try{canvas3d.releasePointerCapture(e.pointerId);}catch(err){}
      render3d();
    }

    canvas3d.addEventListener('pointerup',stop3d);
    canvas3d.addEventListener('pointercancel',stop3d);
    canvas3d.addEventListener('pointerleave',function(e){if((state.drag3d||state.dragNode3d)&&e.buttons===0){stop3d(e);}});

    canvas3d.addEventListener('wheel',function(e){
      e.preventDefault();e.stopPropagation();
      var raw=Number(e.deltaY||0);
      if(e.deltaMode===1){raw*=18;}else if(e.deltaMode===2){raw*=120;}
      raw=Math.max(-240,Math.min(240,raw));
      var oldZoom=state.zoom3d,nextZoom=Math.max(.25,Math.min(5,oldZoom*Math.exp(-raw*0.0024)));

      var rect=canvas3d.getBoundingClientRect(),mx=e.clientX-rect.left,my=e.clientY-rect.top;
      var centerX=rect.width/2+state.pan3dX,centerY=rect.height/2+state.pan3dY;
      if(oldZoom>0){
        var ratio=nextZoom/oldZoom;
        state.pan3dX=(mx-(mx-centerX)*ratio)-rect.width/2;
        state.pan3dY=(my-(my-centerY)*ratio)-rect.height/2;
      }
      state.zoom3d=nextZoom;
      live.textContent='3D zoom: '+Math.round(state.zoom3d*100)+'%.';
      render3d();
    },{passive:false});
  }

  if(panel.classList.contains('is-active')){load(false);}
});
}());
