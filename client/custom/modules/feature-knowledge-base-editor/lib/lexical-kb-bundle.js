var EspoLexical = (function () {
	'use strict';

	/**
	 * Copyright (c) Meta Platforms, Inc. and affiliates.
	 *
	 * This source code is licensed under the MIT license found in the
	 * LICENSE file in the root directory of this source tree.
	 *
	 */

	function t(t,...e){const n=new URL("https://lexical.dev/docs/error"),o=new URLSearchParams;o.append("code",t);for(const t of e)o.append("v",t);throw n.search=o.toString(),Error(`Minified Lexical error #${t}; visit ${n.toString()} for the full message or use the non-minified dev environment for full errors and additional helpful warnings.`)}function e(t,...e){const n=new URL("https://lexical.dev/docs/error"),o=new URLSearchParams;o.append("code",t);for(const t of e)o.append("v",t);n.search=o.toString(),console.warn(`Minified Lexical warning #${t}; visit ${n.toString()} for the full message or use the non-minified dev environment for full errors and additional helpful warnings.`);}const n="undefined"!=typeof window&&void 0!==window.document&&void 0!==window.document.createElement,o=n&&"documentMode"in document?document.documentMode:null,r=n&&/Mac|iPod|iPhone|iPad/.test(navigator.platform),i=n&&/^(?!.*Seamonkey)(?=.*Firefox).*/i.test(navigator.userAgent),s=!(!n||!("InputEvent"in window)||o)&&"getTargetRanges"in new window.InputEvent("input"),l=n&&/iPad|iPhone|iPod/.test(navigator.userAgent)&&!window.MSStream,c=n&&/Android/.test(navigator.userAgent),a=n&&/Version\/[\d.]+.*Safari/.test(navigator.userAgent)&&!c,u=n&&/^(?=.*Chrome).*/i.test(navigator.userAgent),f=n&&c&&u,d=n&&/AppleWebKit\/[\d.]+/.test(navigator.userAgent)&&r&&!u,h=0,g=1,_$1=2,p=1,y=2,m=4,x=8,C=16,S=32,v=64,T=128,b=1,N=2,w=3,E$1=4,O$2=5,M$1=6,A=a||l||d?"\xa0":"\u200b",D$1="\n\n",P=i?"\xa0":A,z$1={bold:1,capitalize:1024,code:16,highlight:T,italic:2,lowercase:256,strikethrough:4,subscript:32,superscript:64,underline:8,uppercase:512},B={directionless:1,unmergeable:2},R$1={center:2,end:6,justify:4,left:1,right:3,start:5},W={[N]:"center",[M$1]:"end",[E$1]:"justify",[b]:"left",[w]:"right",[O$2]:"start"},U={normal:0,segmented:2,token:1},$={[h]:"normal",[_$1]:"segmented",[g]:"token"},J="$config";function j$1(){return Cc()._blockCursorElement}function V$2(t){return null!==t&&1===t.nodeType&&t.hasAttribute("data-lexical-slot")}let q$1 = class q{element;before;after;constructor(t,e,n){this.element=t,this.before=e||null,this.after=n||null;}withBefore(t){return new q(this.element,t,this.after)}withAfter(t){return new q(this.element,this.before,t)}withElement(t){return this.element===t?this:new q(t,this.before,this.after)}insertChild(e){const n=this.getInsertionAnchor();return null!==n&&n.parentElement!==this.element&&t(357),this.element.insertBefore(e,n),this}removeChild(e){return e.parentElement!==this.element&&t(358),this.element.removeChild(e),this}replaceChild(e,n){return n.parentElement!==this.element&&t(359),this.element.replaceChild(e,n),this}getFirstChild(){const t=this.getFirstChildAnchor(),e=t?t.nextSibling:this.element.firstChild;return e===this.getInsertionAnchor()?null:e}getFirstChildAnchor(){return this.after}resolveLeafPosition(t,e,n){if(this.element===t)return e===t&&0===n?"before":"after";const o=Y$2(t,this.element);if(null===o)return "after";const r=Array.prototype.indexOf.call(t.childNodes,o);if(r<0)return "after";if(e===t)return n<=r?"before":"after";const i=Y$2(t,e);if(null===i)return "after";const s=Array.prototype.indexOf.call(t.childNodes,i);return s>=0&&s<=r?"before":"after"}getInsertionAnchor(){return this.before}};function Y$2(t,e){let n=e;for(;null!==n&&n.parentNode!==t;)n=n.parentNode;return n}let G$1 = class G extends q$1{withBefore(t){return new G(this.element,t,this.after)}withAfter(t){return new G(this.element,this.before,t)}withElement(t){return this.element===t?this:new G(t,this.before,this.after)}getInsertionAnchor(){return super.getInsertionAnchor()||this.getManagedLineBreak()}getFirstChildAnchor(){let t=super.getFirstChildAnchor(),e=t?t.nextSibling:this.element.firstChild;for(;V$2(e);)t=e,e=e.nextSibling;const n=t?t.nextSibling:this.element.firstChild;return null!==n&&n===j$1()?n:t}getManagedLineBreak(){return this.element.__lexicalLineBreak||null}setManagedLineBreak(t){if(this.element.__lexicalLastChildKind=t,null===t)this.removeManagedLineBreak();else {const e="decorator"===t&&(d||l||a);this.insertManagedLineBreak(e);}}removeManagedLineBreak(){const t=this.getManagedLineBreak();if(t){const e=this.element,n="IMG"===t.nodeName?t.nextSibling:null;n&&e.removeChild(n),e.removeChild(t),e.__lexicalLineBreak=void 0;}}insertManagedLineBreak(t){const e=this.getManagedLineBreak();if(e){if(t===("IMG"===e.nodeName))return;this.removeManagedLineBreak();}const n=this.element,o=this.before,r=Xl().createElement("br");if(r.setAttribute("data-lexical-managed-linebreak","true"),n.insertBefore(r,o),t){const t=Xl().createElement("img");t.setAttribute("data-lexical-managed-linebreak","true"),t.style.setProperty("display","inline","important"),t.style.setProperty("border","0px","important"),t.style.setProperty("margin","0px","important"),t.alt="",n.insertBefore(t,r),n.__lexicalLineBreak=t;}else n.__lexicalLineBreak=r;}getFirstChildOffset(){const t=this.getFirstChild(),e=this.getInsertionAnchor();let n=0;for(let o=this.element.firstChild;null!==o&&o!==t&&o!==e;o=o.nextSibling)n++;return n}resolveChildIndex(t,e,n,o){if(n===this.element){const e=this.getFirstChildOffset(),n=j$1(),r=this.element.childNodes,i=Math.min(o,r.length);let s=0;for(let t=e;t<i;t++)r[t]!==n&&s++;return [t,Math.min(s,t.getChildrenSize())]}const r=X$2(e,n);r.push(o);const i=X$2(e,this.element);let s=t.getIndexWithinParent();for(let t=0;t<i.length;t++){const e=r[t],n=i[t];if(void 0===e||e<n)break;if(e>n){s+=1;break}}return [t.getParentOrThrow(),s]}};function X$2(e,n){const o=[];let r=n;for(;r!==e&&null!==r;r=r.parentNode){let t=0;for(let e=r.previousSibling;null!==e;e=e.previousSibling)t++;o.push(t);}return r!==e&&t(225),o.reverse()}let Q$2;try{Q$2="0.48.0+prod.esm";}catch(t){}const Z$4=Q$2??'"<unknown>+source"';let tt$3 = class tt{_front=new Set;_back=new Set;_cache;get size(){return this._front.size+this._back.size}addBack(t){return delete this._cache,this._front.has(t)||this._back.add(t),this}addFront(t){return delete this._cache,this._back.has(t)||this._front.add(t),this}delete(t){return delete this._cache,this._front.delete(t)||this._back.delete(t)}toArray(){const t=Array.from(this._front).reverse();for(const e of this._back)t.push(e);return t}toReadonlyArray(){return this._cache=this._cache||this.toArray(),this._cache}[Symbol.iterator](){return this.toReadonlyArray()[Symbol.iterator]()}};const et$3=null;function nt$5(t,e=1e3){return t instanceof ot$4?t.clone():t.size<e?new Map(t):(new ot$4).init(new Map(t),void 0,t.size)}let ot$4 = class ot{_mutable=false;_old=void 0;_nursery=void 0;_size=0;clone(){return this._mutable=false,(new ot).init(this._old,this._nursery,this._size)}init(t,e,n){return this._old=t,this._nursery=e,this._size=n,this}get size(){return this._size}has(t){return void 0!==this.get(t)}getWithTombstone(t){const e=this._nursery&&this._nursery.get(t);return void 0!==e?e:this._old&&this._old.get(t)}get(t){const e=this.getWithTombstone(t);return e===et$3?void 0:e}shouldCompact(){return void 0!==this._nursery&&2*this._nursery.size>this._size}getNursery(){return this._mutable&&this._nursery||(this.compact(),this._nursery=new Map(this._nursery),this._mutable=true),this._nursery}compact(t=false){if(this._nursery&&this._nursery.size>0&&(t||this.shouldCompact())){const t=new Map(this._old);for(const[e,n]of this._nursery)n!==et$3?t.set(e,n):t.delete(e);this._old=t,this._nursery=void 0;}return this._mutable=false,this}set(t,e){const n=this.getWithTombstone(t);if(n===e)return this;const o=this.getNursery();return n!==et$3&&void 0!==n||(this._size++,n===et$3&&o.delete(t)),o.set(t,e),this}delete(t){const e=this.has(t);return e&&(this.getNursery().set(t,et$3),this._size--),e}getOrInsert(t,e){const n=this.get(t);return void 0!==n?n:(this.set(t,e),e)}getOrInsertComputed(t,e){const n=this.get(t);if(void 0!==n)return n;const o=e(t);return this.set(t,o),o}clear(){this._mutable=false,this._old=void 0,this._nursery=void 0,this._size=0;}*keys(){for(const t of this.entries())yield t[0];}*values(){for(const t of this.entries())yield t[1];}*entries(){const t=this._nursery,e=this._old;if(e)for(const n of e){const e=n[0],o=t?t.get(e):void 0;o!==et$3&&(void 0!==o&&(n[1]=o),yield n);}if(t)for(const n of t)n[1]===et$3||e&&e.has(n[0])||(yield n);}forEach(t,e){ void 0!==e&&(t=t.bind(e));for(const[e,n]of this.entries())t(n,e,this);}get[Symbol.toStringTag](){return "GenMap"}[Symbol.iterator](){return this.entries()}};function rt$5(t,e,n,o,r,i){if(Pi(t)){let s=t.getFirstChild();for(;null!==s;){const t=s.__key;s.__parent===e&&((Pi(s)||Yc(s)&&null!==s.__slots)&&rt$5(s,t,n,o,r,i),n.has(t)||i.delete(t),r.push(t)),s=s.getNextSibling();}}for(const s of Yc(t)&&null!==t.__slots?t.__slots.values():[]){const t=o.get(s);void 0!==t&&Gc(t)&&t.__slotHost===e&&((Pi(t)||Yc(t)&&null!==t.__slots)&&rt$5(t,s,n,o,r,i),n.has(s)||i.delete(s),r.push(s));}}let it$4=false,st$5=0;function lt$5(t){st$5=t.timeStamp;}function ct$4(t,e,n){const o="BR"===t.nodeName,r=e.__lexicalLineBreak;return r&&(t===r||o&&t.previousSibling===r)||o&&void 0!==Gs(t,n)}function at$3(t,e,n){const o=Hl(Pl(n)),r=o&&tc(o,n._rootElement);let i=null,s=null;null!==r&&r.anchorNode===t&&(i=r.anchorOffset,s=r.focusOffset);const l=t.nodeValue;null!==l&&al(e,l,i,s,false);}function ut$5(t,e,n){if(cr(t)){const e=t.anchor.getNode();if(e.is(n)&&t.format!==e.getFormat())return  false}return Ls(e)&&n.isAttached()}function ft$4(t,e,n){for(let o=t;o&&!Lc(o);o=Nl(o)){const t=Gs(o,e);if(void 0!==t){const e=Vs(t,n);if(e)return Li(e)||!dc(o)?void 0:[o,e]}}}function dt$7(t,e,n){it$4=true;const o=performance.now()-st$5>100;try{Mi(t,()=>{const r=Lr()||function(t){return t.read("latest",()=>{const t=Lr();return null!==t?t.clone():null})}(t),s=new Map,l=t._editorState,c=t._blockCursorElement;let a=!1,u="";for(let n=0;n<e.length;n++){const f=e[n],d=f.type,h=f.target,g=ft$4(h,t,l);if(!g)continue;const[_,p]=g;if("characterData"===d)o&&Xo(p)&&Ls(h)&&ut$5(r,h,p)&&at$3(h,p,t);else if("childList"===d){a=!0;const e=f.addedNodes;for(let n=0;n<e.length;n++){const o=e[n],r=qs(o),s=o.parentNode;if(!(null==s||o===c||null!==r||ct$4(o,s,t)||t._slotsUsed&&dc(o)&&o.hasAttribute("data-lexical-slot")||Lc(o))){if(i){const t=(dc(o)?o.innerText:null)||o.nodeValue;t&&(u+=t);}s.removeChild(o);}}const n=f.removedNodes,o=n.length;if(o>0){let e=0;for(let r=0;r<o;r++){const o=n[r];(ct$4(o,h,t)||c===o)&&(h.appendChild(o),e++);}o!==e&&s.set(_,p);}}}if(s.size>0)for(const[e,n]of s)n.reconcileObservedMutation(e,t);const f=n.takeRecords();if(f.length>0){for(let e=0;e<f.length;e++){const n=f[e],o=n.addedNodes,r=n.target;for(let e=0;e<o.length;e++){const n=o[e],i=n.parentNode;null==i||"BR"!==n.nodeName||ct$4(n,r,t)||i.removeChild(n);}}n.takeRecords();}null!==r&&(a&&el(r),i&&Tl(t)&&r.insertRawText(u));});}finally{it$4=false;}}function ht$5(t){const e=t._observer;if(null!==e){dt$7(t,e.takeRecords(),e);}}function gt$3(t){!function(t){0===st$5&&Pl(t).addEventListener("textInput",lt$5,true);}(t),t._observer=new MutationObserver((e,n)=>{dt$7(t,e,n);});}const pt$6="latest";let yt$4 = class yt{key;parse;unparse;isEqual;defaultValue;resetOnCopyNode;constructor(t,e){this.key=t,this.parse=e.parse.bind(e),this.unparse=(e.unparse||Et$6).bind(e),this.isEqual=(e.isEqual||Object.is).bind(e),this.defaultValue=this.parse(void 0),this.resetOnCopyNode=e.resetOnCopyNode||false;}};function mt$3(t,e){return new yt$4(t,e)}function xt$4(t,e,n=pt$6){const o=(n===pt$6?t.getLatest():t).__state;return o?o.getValue(e):e.defaultValue}function St$4(t,e,n){let o;if(ui(),"function"==typeof n){const r=t.getLatest(),i=xt$4(r,e);if(o=n(i),e.isEqual(i,o))return r}else o=n;const r=t.getWritable();return bt$3(r).updateFromKnown(e,o),r}function vt$5(t){const e=new Map,n=new Set;for(const{ownNodeConfig:o}of Uc("function"==typeof t?t:t.replace))if(o&&o.stateConfigs)for(const t of o.stateConfigs){let o;"stateConfig"in t?(o=t.stateConfig,t.flat&&n.add(o.key)):o=t,e.set(o.key,o);}return {flatKeys:n,sharedConfigMap:e}}const Tt$5=new Set(["__proto__","constructor","prototype"]);let kt$4 = class kt{node;knownState;unknownState;sharedNodeState;size;constructor(t,e,n=void 0,o=new Map,r=void 0){this.node=t,this.sharedNodeState=e,this.unknownState=n,this.knownState=o;const{sharedConfigMap:i}=this.sharedNodeState,s=void 0!==r?r:function(t,e,n){let o=n.size;if(e)for(const r in e){const e=t.get(r);e&&n.has(e)||o++;}return o}(i,n,o);this.size=s;}getValue(t){const e=this.knownState.get(t);if(void 0!==e)return e;this.sharedNodeState.sharedConfigMap.set(t.key,t);let n=t.defaultValue;if(this.unknownState&&t.key in this.unknownState){const e=this.unknownState[t.key];void 0!==e&&(n=t.parse(e)),this.updateFromKnown(t,n);}return n}getInternalState(){return [this.unknownState,this.knownState]}toJSON(){const t={...this.unknownState},e={};for(const[e,n]of this.knownState)e.isEqual(n,e.defaultValue)?delete t[e.key]:t[e.key]=e.unparse(n);for(const n of this.sharedNodeState.flatKeys)n in t&&(e[n]=t[n],delete t[n]);return wt$4(t)&&(e.$=t),e}getWritable(t){if(this.node===t)return this;const{sharedNodeState:e,unknownState:n}=this,o=new Map(this.knownState);return new kt(t,e,function(t,e,n){let o;if(n)for(const[r,i]of Object.entries(n)){if(Tt$5.has(r))continue;const n=t.get(r);n?e.has(n)||e.set(n,n.parse(i)):(o=o||{},o[r]=i);}return o}(e.sharedConfigMap,o,n),o,this.size)}resetOnCopyNode(){for(const t of this.knownState.keys())t.resetOnCopyNode&&this.knownState.set(t,t.defaultValue);return this}updateFromKnown(t,e){const n=t.key;this.sharedNodeState.sharedConfigMap.set(n,t);const{knownState:o,unknownState:r}=this;o.has(t)||r&&n in r||(r&&(delete r[n],this.unknownState=wt$4(r)),this.size++),o.set(t,e);}updateFromUnknown(t,e){if(Tt$5.has(t))return;const n=this.sharedNodeState.sharedConfigMap.get(t);n?this.updateFromKnown(n,n.parse(e)):(this.unknownState=this.unknownState||{},t in this.unknownState||this.size++,this.unknownState[t]=e);}updateFromJSON(t){const{knownState:e}=this;for(const t of e.keys())e.set(t,t.defaultValue);if(this.size=e.size,this.unknownState=void 0,t)for(const[e,n]of Object.entries(t))this.updateFromUnknown(e,n);}};function bt$3(t){const e=t.getWritable(),n=e.__state?e.__state.getWritable(e):new kt$4(e,Nt$5(e));return e.__state=n,n}function Nt$5(t){return t.__state?t.__state.sharedNodeState:ks(Cc(),t.getType()).sharedNodeState}function wt$4(t){if(t)for(const e in t)return t}function Et$6(t){return t}function Ot$5(t,e,n){for(const[o,r]of e.knownState){if(t.has(o.key))continue;t.add(o.key);const e=n?n.getValue(o):o.defaultValue;if(e!==r&&!o.isEqual(e,r))return  true}return  false}function Mt$5(t,e,n){const{unknownState:o}=e,r=n?n.unknownState:void 0;if(o)for(const[e,n]of Object.entries(o)){if(t.has(e))continue;t.add(e);if(n!==(r?r[e]:void 0))return  true}return  false}function At$4(t,e){const n=t.__state;return n&&n.node===t?n.getWritable(e):n}function Dt$6(t,e){const n=t.__mode,o=t.__format,r=t.__style,i=e.__mode,s=e.__format,l=e.__style,c=t.__state,a=e.__state;return (null===n||n===i)&&(null===o||o===s)&&(null===r||r===l)&&(null===t.__state||c===a||function(t,e){if(t===e)return  true;const n=new Set;return !(t&&Ot$5(n,t,e)||e&&Ot$5(n,e,t)||t&&Mt$5(n,t,e)||e&&Mt$5(n,e,t))}(c,a))}function Pt$5(t,e){const n=t.mergeWithSibling(e),o=gi()._normalizedNodes;return o.add(t.__key),o.add(e.__key),n}function Ft$5(t){let e,n,o=t;if(""!==o.__text||!o.isSimpleText()||o.isUnmergeable()){for(;null!==(e=o.getPreviousSibling())&&Xo(e)&&e.isSimpleText()&&!e.isUnmergeable();){if(""!==e.__text){if(Dt$6(e,o)){o=Pt$5(e,o);break}break}e.remove();}for(;null!==(n=o.getNextSibling())&&Xo(n)&&n.isSimpleText()&&!n.isUnmergeable();){if(""!==n.__text){if(Dt$6(o,n)){o=Pt$5(o,n);break}break}n.remove();}}else o.remove();}function It$5(t){return Lt$3(t.anchor),Lt$3(t.focus),t}function Lt$3(t){for(;"element"===t.type;){const e=t.getNode(),n=t.offset;let o,r;if(n===e.getChildrenSize()?(o=e.getChildAtIndex(n-1),r=true):(o=e.getChildAtIndex(n),r=false),Xo(o)){t.set(o.__key,r?o.getTextContentSize():0,"text",true);break}if(!Pi(o))break;t.set(o.__key,r?o.getChildrenSize():0,"element",true);}}const Kt$4=Symbol.for("@lexical/CachedTextSize");function zt$6(e,n){return ee$4.read(()=>{let o=0,r=e;for(let e=0;e<n&&null!==r;e++){const i=te$4.get(r);if(void 0===i&&t(345,r),Pi(i)){const s=ne$3.get(r);if(void 0!==s&&Pi(s)&&s.__parent!==i.__parent)o+=i.getTextContentSize();else {const e=oe$3.get(r),n=e&&e.__lexicalTextContent;"string"!=typeof n&&t(346,i.getType()),o+=n.length;}e<n-1&&!i.isInline()&&(o+=2);}else {const e=i[Kt$4];void 0===e&&t(347,i.getType(),r),o+=e;}r=i.__next;}return o},{editor:Ut$3})}function Bt$4(t){Pi(t)||void 0===t[Kt$4]&&(t[Kt$4]=Xo(t)?t.__text.length:t.getTextContentSize());}const Rt$4=4;let Wt$4,Ut$3,$t$7,Ht$4="",Jt$2=null,jt$4=null,Vt$4=null;function qt$4(){return {firstTextKey:Vt$4,format:Jt$2,style:jt$4}}function Yt$3(t){null!==t.firstTextKey&&(Jt$2=t.format,jt$4=t.style,Vt$4=t.firstTextKey);}function Gt$2(e){if(null!==Vt$4)return;const n=e.__lexicalFirstTextKey;if(void 0===n&&t(348),null===n)return;const o=ne$3.get(n);Xo(o)&&(Jt$2=o.getFormat(),jt$4=o.getStyle(),Vt$4=n);}let Xt$4,Qt$2,Zt$4,te$4,ee$4,ne$3,oe$3,re$4,ie$3,se$3,le$4=false,ce$3=false;function ae$2(t,e){const n=te$4.get(t),o=ne$3.has(t);if(null!==e){const n=Pe$3(t);n.parentNode===e&&e.removeChild(n);}if(!o){if(Ut$3._keyToDOMMap.delete(t),Pi(n)){const t=jc(n,te$4);ue$3(t,0,t.length-1,null);}if(void 0!==n){for(const t of xe$3(n).values()){const e=Se$2(t);ae$2(t,null),null!==e&&e.remove();}xl(ie$3,$t$7,Xt$4,n,"destroyed");}}}function ue$3(t,e,n,o){for(let r=e;r<=n;++r){const e=t[r];void 0!==e&&ae$2(e,o);}}function fe$2(t,e){t.setProperty("text-align",e);}const de$2="40px";function he$2(t,e){const n=Wt$4.theme.indent;if("string"==typeof n){const o=t.classList.contains(n);e>0&&!o?t.classList.add(n):e<1&&o&&t.classList.remove(n);}t.style.setProperty("padding-inline-start",0===e?"":`calc(${e} * var(--lexical-indent-base-value, ${de$2}))`);}function ge$2(t,e){const n=t.style;0===e?fe$2(n,""):1===e?fe$2(n,"left"):2===e?fe$2(n,"center"):3===e?fe$2(n,"right"):4===e?fe$2(n,"justify"):5===e?fe$2(n,"start"):6===e&&fe$2(n,"end");}function _e$2(t,e){const n=function(t){const e=t.__dir;if(null!==e)return e;if(zi(t))return null;const n=t.getParent();return null===n||Kl(n)&&null===n.__dir?"auto":null}(e);null!==n?t.dir=n:t.removeAttribute("dir");}function pe$2(t){const e=Xl().createElement("div");return e.setAttribute("data-lexical-slot",t),e.style.display="none",e}function ye$2(t,e,n){e||"false"===t.contentEditable?Kc(n,Ut$3):n.removeAttribute("contenteditable");}function me$2(t,e,n){const o=Ht$4,r=qt$4();Ht$4="";let i="";const s=Li(t);for(const[o,r]of n){const n=pe$2(o);ye$2(e,s,n),e.appendChild(n),Ht$4="";const l=qt$4();Te$4(r,vc(t,n,Ut$3)),Yt$3(l),Ce$2(t,o,e,n),i+=Ht$4;}return Yt$3(r),Ht$4=o,i}function xe$3(t){return Yc(t)&&null!==t.__slots?t.__slots:qc}function Ce$2(t,e,n,o){const r=se$3.$getSlotTargetElement(t,e,n,Ut$3);null!==r&&(o.parentElement!==r&&r.appendChild(o),o.style.display="");}function Se$2(t){const e=oe$3.get(t);return void 0!==e?e.parentElement:null}function ve$2(t,e,n){const o=xe$3(t),r=xe$3(e);for(const[t,e]of o)if(!r.has(t)){const t=Se$2(e);ae$2(e,null),null!==t&&t.remove();}const i=Ht$4,s=qt$4();let l="",c=null;const a=Li(e);for(const[t,i]of r){const r=o.get(t);let s=void 0!==r?Se$2(r):null;Ht$4="";const u=qt$4();if(null===s){s=pe$2(t);let o=null;for(const t of n.children)if(!t.hasAttribute("data-lexical-slot")){o=t;break}n.insertBefore(s,o),Te$4(i,vc(e,s,Ut$3));}else r===i?Ee$1(i,s):(void 0!==r&&ae$2(r,s),Te$4(i,vc(e,s,Ut$3)));if(Yt$3(u),ye$2(n,a,s),Ce$2(e,t,n,s),l+=Ht$4,s.parentElement===n){const t=null===c?n.firstChild:c.nextSibling;t!==s&&n.insertBefore(s,t),c=s;}}return Yt$3(s),Ht$4=i,l}function Te$4(e,n){const o=ne$3.get(e);if(void 0===o&&t(60),null!==n){const t=te$4.get(e);if(void 0!==t){const r=oe$3.get(e);if(void 0!==r){const i=Gc(t)?t.__slotHost:null,s=Gc(o)?o.__slotHost:null,l=t.__parent!==o.__parent||i!==s,c=null!==s&&r.parentElement!==n.element;if(l||c)return n.insertChild(r),Ee$1(e,n.element)}}}const r=se$3.$createDOM(o,Ut$3);if(function(t,e,n){const o=n._keyToDOMMap;Ys(e,n,t),o.set(t,e);}(e,r,Ut$3),Xo(o)?r.setAttribute("data-lexical-text","true"):Li(o)&&(r.setAttribute("data-lexical-decorator","true"),Ic(r,{captureSelection:true})),Pi(o)){const t=o.__indent,e=o.__size;_e$2(r,o),0!==t&&he$2(r,t);const n=xe$3(o),i=n.size>0?me$2(o,r,n):"";if(0===e)r.__lexicalTextContent=i,r.__lexicalFirstTextKey=null,Ht$4+=i,n.size>0&&(r.__lexicalSlotTextLength=i.length);else {const t=Ht$4,s=e-1;if(ke$3(jc(o,ne$3),o,0,s,vc(o,r,Ut$3)),""!==i){const e=r.__lexicalTextContent||"";r.__lexicalTextContent=i+e,Ht$4=t+i+e;}n.size>0&&(r.__lexicalSlotTextLength=i.length);}const s=o.__format;0!==s&&ge$2(r,s),o.isInline()||be$1(null,o,r);}else {const t=o.getTextContent();if(Li(o)){const t=o.decorate(Ut$3,Wt$4);null!==t&&Oe$2(e,t),r.contentEditable="false";const n=xe$3(o);n.size>0&&me$2(o,r,n);}Ht$4+=t;}return null!==n&&n.insertChild(r),se$3.$decorateDOM(o,null,r,Ut$3),Bt$4(o),xl(ie$3,$t$7,Xt$4,o,"created"),r}function ke$3(e,n,o,r,i){const s=Ht$4,l=qt$4();Ht$4="",Jt$2=null,jt$4=null,Vt$4=null;let c=o;for(;c<=r;++c){const t=qt$4();Te$4(e[c],i);const n=ne$3.get(e[c]);null!==n&&Xo(n)?null===Jt$2&&(Jt$2=n.getFormat(),jt$4=n.getStyle(),Vt$4=n.__key):Pi(n)&&c<r&&!n.isInline()&&(Ht$4+=D$1),Yt$3(t);}const a=Ut$3._keyToDOMMap.get(n.__key);void 0===a&&t(349,n.__key),a.__lexicalTextContent=Ht$4,a.__lexicalFirstTextKey=Vt$4,Ht$4=s+Ht$4,Yt$3(l);}function be$1(t,e,n){const o=vc(e,n,Ut$3),r=o.element.__lexicalLastChildKind??null,i=function(t,e){if(t){const n=t.__last;if(n){const t=e.get(n);if(t)return qi(t)?"line-break":Li(t)&&t.isInline()?"decorator":null}return xe$3(t).size>0?null:"empty"}return null}(e,ne$3);r!==i&&o.setManagedLineBreak(i);}function Ne$1(e,n,o){var r;Jt$2=null,jt$4=null,Vt$4=null,function(e,n,o){const r=Ht$4,i=e.__size,s=n.__size;Ht$4="";const l=o.element,c=Ut$3._keyToDOMMap.get(n.__key);void 0===c&&t(351,n.__key);const a=s-i;if(!le$4&&Math.abs(a)<=1&&i>=Rt$4&&e.__first===n.__first&&(0!==a||!Ut$3._cloneNotNeeded.has(e.__key))){const i=c.__lexicalTextContent,u=re$4.get(e.__key);if(!le$4&&"string"==typeof i&&void 0!==u){const s=function(t,e){const n=e.size;if(0===n||n>=t.__size)return null;let o=t.__last,r=null,i=0;for(;null!==o&&i<n;){if(!e.has(o))return null;r=o;const t=ne$3.get(o);if(void 0===t)return null;o=t.__prev,i++;}if(i!==n)return null;if(null!==o&&e.has(o))return null;return r}(n,u);if(null!==s){const f=u.size;if(0===a){const e=zt$6(s,f);let o=s,a=0;for(;null!==o&&a<f;){const t=ne$3.get(o);if(void 0===t)break;const e=qt$4();Ee$1(o,l),Xo(t)&&null===Jt$2&&(Jt$2=t.getFormat(),jt$4=t.getStyle(),Vt$4=t.__key),Yt$3(e),o=t.__next,a++;}let d="";for(o=s,a=0;null!==o&&a<f;){const e=ne$3.get(o);if(void 0===e)break;let n;if(Pi(e)){const r=Ut$3._keyToDOMMap.get(o),i=r&&r.__lexicalTextContent;"string"!=typeof i&&t(352,e.getType()),n=i;}else n=e.getTextContent();d+=n,a<f-1&&Pi(e)&&!e.isInline()&&(d+=D$1),o=e.__next,a++;}const h=c.__lexicalSlotTextLength||0,g=h>0?i.slice(h):i,_=g.slice(0,g.length-e)+d;return c.__lexicalTextContent=_,Ht$4=r+_,void we$2(n,c,u)}if(function(e,n,o,r,i,s,l,c){if(1!==c&&-1!==c)return  false;const a=1===c?2:1;if(l!==a)return  false;const u=l-c;let f=e.__last;for(let t=0;t<u-1;t++){if(null===f)return  false;const t=te$4.get(f);if(void 0===t)return  false;f=t.__prev;}if(null===f)return  false;const d=ne$3.get(s),h=te$4.get(f);if(void 0===d||void 0===h)return  false;if(d.__prev!==h.__prev)return  false;const g=[];let _=s;for(let t=0;t<l;t++){if(null===_)return  false;g.push(_);const t=ne$3.get(_);_=t?t.__next:null;}const p=[];_=f;for(let t=0;t<u;t++){if(null===_)return  false;p.push(_);const t=te$4.get(_);_=t?t.__next:null;}const y=new Set(p),m=new Set(g),x=[];let C=0,S=0;for(;C<u&&S<l;)if(g[S]===p[C])x.push({key:g[S],kind:"reconcile"}),C++,S++;else if(m.has(p[C])){if(y.has(g[S]))return  false;x.push({key:g[S],kind:"create",nextIndex:S}),S++;}else x.push({key:p[C],kind:"destroy"}),C++;for(;C<u;)x.push({key:p[C++],kind:"destroy"});for(;S<l;)x.push({key:g[S],kind:"create",nextIndex:S}),S++;const v=zt$6(f,u);for(const t of x){const e=qt$4();if("reconcile"===t.kind)Ee$1(t.key,o.element);else if("destroy"===t.kind)ae$2(t.key,o.element);else {let e=null;for(let n=t.nextIndex+1;n<l;n++){const t=Ut$3._keyToDOMMap.get(g[n]);if(void 0!==t){e=t;break}}Te$4(t.key,o.withBefore(e??o.before));}if("destroy"!==t.kind){const e=ne$3.get(t.key);e&&Xo(e)&&null===Jt$2&&(Jt$2=e.getFormat(),jt$4=e.getStyle(),Vt$4=e.__key);}Yt$3(e);}let T="";for(let e=0;e<l;e++){const n=ne$3.get(g[e]);if(void 0===n)return  false;let o;if(Pi(n)){const r=Ut$3._keyToDOMMap.get(g[e]),i=r&&r.__lexicalTextContent;"string"!=typeof i&&t(350,n.getType()),o=i;}else o=n.getTextContent();T+=o,e<l-1&&Pi(n)&&!n.isInline()&&(T+=D$1);}const k=r.__lexicalSlotTextLength||0,b=k>0?i.slice(k):i;return r.__lexicalTextContent=b.slice(0,b.length-v)+T,true}(e,0,o,c,i,s,f,a)){const e=c.__lexicalTextContent;return "string"!=typeof e&&t(353),Ht$4=r+e,void we$2(n,c,u)}}}if(0===a){let n=e.__first,o=0;for(;null!==n;){const e=ne$3.get(n);if(void 0===e)break;const r=le$4||Zt$4.has(n)||Qt$2.has(n),i=qt$4();if(r)Ee$1(n,l);else {let o,r;if(Pi(e)){r=oe$3.get(n);const i=r&&r.__lexicalTextContent;"string"!=typeof i&&t(354,e.getType()),o=i;}else o=e.getTextContent();Ht$4+=o,void 0!==r&&Gt$2(r);}Xo(e)?null===Jt$2&&(Jt$2=e.getFormat(),jt$4=e.getStyle(),Vt$4=e.__key):Pi(e)&&o<s-1&&!e.isInline()&&(Ht$4+=D$1),Yt$3(i),n=e.__next,o++;}return c.__lexicalTextContent=Ht$4,c.__lexicalFirstTextKey=Vt$4,void(Ht$4=r+Ht$4)}}if(1===i&&1===s){const t=e.__first,r=n.__first;if(t===r)Ee$1(t,l);else {const e=Pe$3(t),n=Te$4(r,null);try{e.parentNode===l?l.replaceChild(n,e):o.insertChild(n);}catch(o){if("object"==typeof o&&null!=o){const i=`${o.toString()} Parent: ${l.tagName}, new child: {tag: ${n.tagName} key: ${r}}, old child: {tag: ${e.tagName}, key: ${t}}.`;throw new Error(i)}throw o}ae$2(t,null);}const i=ne$3.get(r);Xo(i)&&null===Jt$2&&(Jt$2=i.getFormat(),jt$4=i.getStyle(),Vt$4=i.__key);}else {const r=jc(e,te$4),c=jc(n,ne$3);if(r.length!==i&&t(227),c.length!==s&&t(228),0===i)0!==s&&ke$3(c,n,0,s-1,o);else if(0===s){if(0!==i){const t=null==o.after&&null==o.before&&0===xe$3(n).size&&null==o.element.__lexicalLineBreak;ue$3(r,0,i-1,t?null:l),t&&(l.textContent="");}}else !function(t,e,n,o,r,i){const s=o-1,l=r-1;let c,a,u=i.getFirstChild(),f=0,d=0;for(;f<=s&&d<=l;){const t=e[f],o=n[d],r=qt$4();if(t===o)u=Me$2(Ee$1(o,i.element)),f++,d++;else {if(void 0===a&&(a=Ae$2(n,d)),void 0===c)c=Ae$2(e,f);else if(!c.has(t)){f++,Yt$3(r);continue}if(!a.has(t)){u=Me$2(Pe$3(t)),ae$2(t,i.element),f++,c.delete(t),Yt$3(r);continue}if(c.has(o)){const t=bl(Ut$3,o);t!==u&&i.withBefore(u??i.before).insertChild(t),u=Me$2(Ee$1(o,i.element)),f++,d++;}else Te$4(o,i.withBefore(u??i.before)),d++;}const s=ne$3.get(o);null!==s&&Xo(s)?null===Jt$2&&(Jt$2=s.getFormat(),jt$4=s.getStyle(),Vt$4=s.__key):Pi(s)&&d<=l&&!s.isInline()&&(Ht$4+=D$1),Yt$3(r);}const h=f>s,g=d>l;if(h&&!g){const e=n[l+1],o=void 0===e?null:Ut$3.getElementByKey(e);ke$3(n,t,d,l,i.withBefore(o??i.before));}else g&&!h&&ue$3(e,f,s,i.element);}(n,r,c,i,s,o);}c.__lexicalTextContent=Ht$4,c.__lexicalFirstTextKey=Vt$4,Ht$4=r+Ht$4;}(e,n,vc(n,o,Ut$3)),Kl(n)||(r=n,null==Jt$2||Jt$2===r.__textFormat||ce$3||r.setTextFormat(Jt$2),function(t){null==jt$4||jt$4===t.__textStyle||ce$3||t.setTextStyle(jt$4);}(n));}function we$2(t,e,n){const o=e.__lexicalFirstTextKey;if(null!=o){const e=t.__key;let r=o;for(;null!==r;){const t=ne$3.get(r);if(void 0===t){r=null;break}if(t.__parent===e)break;r=t.__parent;}if(null!==r&&!n.has(r)){const t=ne$3.get(o);if(Xo(t))return Jt$2=t.getFormat(),void(jt$4=t.getStyle())}}e.__lexicalFirstTextKey=Vt$4;}function Ee$1(e,n){const o=te$4.get(e);let r=ne$3.get(e);void 0!==o&&void 0!==r||t(61);const i=le$4||Zt$4.has(e)||Qt$2.has(e),s=bl(Ut$3,e);if(o===r&&!i){let e;if(Pi(o)){const n=s.__lexicalTextContent;"string"!=typeof n&&t(355,o.getType()),e=n,Gt$2(s);}else e=o.getTextContent();return Ht$4+=e,s}if(o!==r&&i&&xl(ie$3,$t$7,Xt$4,r,"updated"),se$3.$updateDOM(r,o,s,Ut$3)){const o=Te$4(e,null);return null===n&&t(62),n.replaceChild(o,s),ae$2(e,null),o}if(Pi(o)){Pi(r)||t(334,e);const n=r.__indent;(le$4||n!==o.__indent)&&he$2(s,n);const l=r.__format;(le$4||l!==o.__format)&&ge$2(s,l);const c=i&&(xe$3(r).size>0||xe$3(o).size>0)?ve$2(o,r,s):"";if(i){const t=Ht$4;if(Ne$1(o,r,s),zi(r)||r.isInline()||be$1(0,r,s),""!==c){const e=s.__lexicalTextContent||"";s.__lexicalTextContent=c+e,Ht$4=t+c+e,s.__lexicalSlotTextLength=c.length;}else (xe$3(r).size>0||xe$3(o).size>0)&&(s.__lexicalSlotTextLength=0);}else {const e=s.__lexicalTextContent;"string"!=typeof e&&t(356,o.getType()),Ht$4+=e,Gt$2(s);}if((le$4||r.__dir!==o.__dir||r.__parent!==o.__parent)&&(_e$2(s,r),zi(r)&&!le$4))for(const t of r.getChildren())if(Pi(t)){_e$2(bl(Ut$3,t.getKey()),t);}}else {const t=r.getTextContent();if(Li(r)){const t=r.decorate(Ut$3,Wt$4);null!==t&&Oe$2(e,t),i&&(xe$3(r).size>0||xe$3(o).size>0)&&ve$2(o,r,s);}Ht$4+=t;}if(!ce$3&&zi(r)){const t=r.getLatest();if(t.__cachedText!==Ht$4){const e=t.getWritable();e.__cachedText=Ht$4,r=e;}}return se$3.$decorateDOM(r,o,s,Ut$3),Bt$4(r),s}function Oe$2(t,e){let n=Ut$3._pendingDecorators;const o=Ut$3._decorators;if(null===n){if(o[t]===e)return;n=Qs(Ut$3);}n[t]=e;}function Me$2(t){let e=t.nextSibling;return null!==e&&e===Ut$3._blockCursorElement&&(e=e.nextSibling),e}function Ae$2(t,e){const n=new Set;for(let o=e;o<t.length;o++)n.add(t[o]);return n}function De$2(t,e,n,o,r,i){Ht$4="",Jt$2=null,jt$4=null,Vt$4=null,le$4=2===o,Ut$3=n,Wt$4=n._config,se$3=n._config.dom||_s,$t$7=n._nodes,Xt$4=Ut$3._listeners.mutation,Qt$2=r,Zt$4=i,te$4=t._nodeMap,ee$4=t,ne$3=e._nodeMap,ce$3=e._readOnly,oe$3=nt$5(n._keyToDOMMap),re$4=function(){const t=new Map,e=e=>{for(const n of e){const e=ne$3.get(n);if(void 0===e)continue;const o=e.__parent;if(null===o)continue;let r=t.get(o);void 0===r&&(r=new Set,t.set(o,r)),r.add(n);}};return e(Qt$2.keys()),e(Zt$4),t}();const s=new Map;return ie$3=s,Ee$1("root",null),Ut$3=void 0,$t$7=void 0,Qt$2=void 0,Zt$4=void 0,te$4=void 0,ee$4=void 0,ne$3=void 0,Wt$4=void 0,oe$3=void 0,re$4=void 0,ie$3=void 0,se$3=_s,s}function Pe$3(e){const n=oe$3.get(e);return void 0===n&&t(75,e),n}
	/*@__INLINE__*/function Fe$3(t){return {type:t}}const Ie$2=/* @__PURE__ */Fe$3("SELECTION_CHANGE_COMMAND"),Le$3=/* @__PURE__ */Fe$3("SELECTION_INSERT_CLIPBOARD_NODES_COMMAND"),Ke$3=/* @__PURE__ */Fe$3("CLICK_COMMAND"),ze$2=/* @__PURE__ */Fe$3("BEFORE_INPUT_COMMAND"),Be$3=/* @__PURE__ */Fe$3("INPUT_COMMAND"),Re$1=/* @__PURE__ */Fe$3("COMPOSITION_START_COMMAND"),We$2=/* @__PURE__ */Fe$3("COMPOSITION_END_COMMAND"),Ue$2=/* @__PURE__ */Fe$3("DELETE_CHARACTER_COMMAND"),$e$3=/* @__PURE__ */Fe$3("INSERT_LINE_BREAK_COMMAND"),He$3=/* @__PURE__ */Fe$3("INSERT_PARAGRAPH_COMMAND"),Je$3=/* @__PURE__ */Fe$3("CONTROLLED_TEXT_INSERTION_COMMAND"),je$2=/* @__PURE__ */Fe$3("PASTE_COMMAND"),Ve$2=/* @__PURE__ */Fe$3("REMOVE_TEXT_COMMAND"),qe$3=/* @__PURE__ */Fe$3("DELETE_WORD_COMMAND"),Ye$1=/* @__PURE__ */Fe$3("DELETE_LINE_COMMAND"),Ge$2=/* @__PURE__ */Fe$3("FORMAT_TEXT_COMMAND"),Xe$2=/* @__PURE__ */Fe$3("SET_TEXT_FORMAT_COMMAND"),Qe$2=/* @__PURE__ */Fe$3("UNDO_COMMAND"),Ze$2=/* @__PURE__ */Fe$3("REDO_COMMAND"),tn$3=/* @__PURE__ */Fe$3("KEYDOWN_COMMAND"),en$3=/* @__PURE__ */Fe$3("KEY_ARROW_RIGHT_COMMAND"),nn$2=/* @__PURE__ */Fe$3("MOVE_TO_END"),on$2=/* @__PURE__ */Fe$3("KEY_ARROW_LEFT_COMMAND"),rn$2=/* @__PURE__ */Fe$3("MOVE_TO_START"),sn$2=/* @__PURE__ */Fe$3("KEY_ARROW_UP_COMMAND"),ln$2=/* @__PURE__ */Fe$3("KEY_ARROW_DOWN_COMMAND"),cn$1=/* @__PURE__ */Fe$3("KEY_ENTER_COMMAND"),an$1=/* @__PURE__ */Fe$3("KEY_SPACE_COMMAND"),un$2=/* @__PURE__ */Fe$3("KEY_BACKSPACE_COMMAND"),fn$2=/* @__PURE__ */Fe$3("KEY_ESCAPE_COMMAND"),dn$2=/* @__PURE__ */Fe$3("KEY_DELETE_COMMAND"),hn$2=/* @__PURE__ */Fe$3("KEY_TAB_COMMAND"),gn$2=/* @__PURE__ */Fe$3("INSERT_TAB_COMMAND"),_n$1=/* @__PURE__ */Fe$3("INDENT_CONTENT_COMMAND"),pn$2=/* @__PURE__ */Fe$3("OUTDENT_CONTENT_COMMAND"),yn$1=/* @__PURE__ */Fe$3("DROP_COMMAND"),mn$1=/* @__PURE__ */Fe$3("FORMAT_ELEMENT_COMMAND"),xn$1=/* @__PURE__ */Fe$3("DRAGSTART_COMMAND"),Cn$2=/* @__PURE__ */Fe$3("DRAGOVER_COMMAND"),Sn$1=/* @__PURE__ */Fe$3("DRAGEND_COMMAND"),vn$1=/* @__PURE__ */Fe$3("COPY_COMMAND"),Tn$1=/* @__PURE__ */Fe$3("CUT_COMMAND"),kn$2=/* @__PURE__ */Fe$3("SELECT_ALL_COMMAND"),bn$2=/* @__PURE__ */Fe$3("CLEAR_EDITOR_COMMAND"),Nn$2=/* @__PURE__ */Fe$3("CLEAR_HISTORY_COMMAND"),wn$1=/* @__PURE__ */Fe$3("CAN_REDO_COMMAND"),En$2=/* @__PURE__ */Fe$3("CAN_UNDO_COMMAND"),On$2=/* @__PURE__ */Fe$3("FOCUS_COMMAND"),Mn$2=/* @__PURE__ */Fe$3("BLUR_COMMAND"),An$1=/* @__PURE__ */Fe$3("KEY_MODIFIER_COMMAND");function Dn$2(t){const e=new Map;return {dispose(){for(const t of e.values())t.dispose();e.clear();},register(n,o){let r=e.get(n);void 0===r&&(r={dispose:t(n,o),holders:new Set},e.set(n,r));const i=()=>{const t=e.get(n);t&&t.holders.delete(i)&&0===t.holders.size&&(e.delete(n),t.dispose());};return r.holders.add(i),i}}}function Pn$1(t,e,n,o){return t.addEventListener(e,n,o),t.removeEventListener.bind(t,e,n,o)}const Fn$1=Object.freeze({}),In$1=[["keydown",function(t,e){const n=e._inputState;n.lastKeyDownTimeStamp=t.timeStamp,n.lastKeyCode=t.key,"Backspace"!==t.key&&jn$1(n);if(e.isComposing())return;kl(e,tn$3,t);}],["pointerdown",function(t,e){const n=lc(t),o=t.pointerType;hc(n)&&"touch"!==o&&"pen"!==o&&0===t.button&&Mi(e,()=>{zc(n,e)||(e._inputState.isSelectionChangeFromMouseDown=true);});}],["compositionstart",function(t,e){kl(e,Re$1,t);}],["compositionend",function(t,e){const n=e._inputState;i?n.compositionPhase="ending-firefox":l||!a&&!d?kl(e,We$2,t):(n.compositionPhase="ending-safari",n.compositionEndData=t.data);}],["input",function(t,e){t.stopPropagation();const n=e._inputState;jn$1(n),Mi(e,()=>{qn$1(t,e)||e.dispatchCommand(Be$3,t);},{event:t}),n.unprocessedBeforeInputData=null;}],["click",function(t,e){Mi(e,()=>{const n=Lr(),o=Hl(Pl(e)),r=Kr();if(o)if(cr(n)){const t=n.anchor,e=t.getNode();"element"===t.type&&0===t.offset&&n.isCollapsed()&&!zi(e)&&1===tl().getChildrenSize()&&e.getTopLevelElementOrThrow().isEmpty()&&null!==r&&n.is(r)&&(o.removeAllRanges(),n.dirty=true);}else if("touch"===t.pointerType||"pen"===t.pointerType){const n=tc(o,e._rootElement).anchorNode;if(dc(n)||Ls(n)){el(Ir(r,o,e,t));}}kl(e,Ke$3,t);});}],["cut",Fn$1],["copy",Fn$1],["dragstart",Fn$1],["dragover",Fn$1],["dragend",Fn$1],["paste",Fn$1],["focus",Fn$1],["blur",Fn$1],["drop",Fn$1]];s&&In$1.push(["beforeinput",(t,e)=>function(t,e){const n=t.inputType;if("deleteCompositionText"===n||i&&Tl(e))return;if("insertCompositionText"===n)return;Mi(e,()=>{qn$1(t,e)||kl(e,ze$2,t);},{event:t});}(t,e)]);const Ln$1=new WeakMap,Kn$1=new WeakMap,zn$1=Dn$2(t=>(t.addEventListener("selectionchange",ro$1),()=>t.removeEventListener("selectionchange",ro$1)));function Bn$1(t,e,n,o,r,i){const l=t.anchor,c=t.focus,a=l.getNode(),u=gi();let f;if(void 0!==i)f=i;else {const t=Hl(Pl(u));f=null!==t?tc(t,u._rootElement):null;}const d=null!==f?f.anchorNode:null,h=l.key,g=u.getElementByKey(h),_=n.length;return h!==c.key||!Xo(a)||(!r&&(!s||u._inputState.lastBeforeInputInsertTextTimeStamp<o+50)||a.isDirty()&&_<2||rl(n))&&l.offset!==c.offset&&!a.isComposing()||Is(a)||a.isDirty()&&_>1||(r||!s)&&null!==g&&!a.isComposing()&&d!==Nc(a,g,u)||null!==f&&null!==e&&(!e.collapsed||e.startContainer!==f.anchorNode||e.startOffset!==f.anchorOffset)||!a.isComposing()&&(a.getFormat()!==t.format||a.getStyle()!==t.style)||function(t,e){if(e.isSegmented())return  true;if(!t.isCollapsed())return  false;const n=t.anchor.offset,o=e.getParentOrThrow(),r=Fs(e);return 0===n?!e.canInsertTextBefore()||!o.canInsertTextBefore()&&!e.isComposing()||r||function(t){const e=t.getPreviousSibling();return (Xo(e)||Pi(e)&&e.isInline())&&!e.canInsertTextAfter()}(e):n===e.getTextContentSize()&&(!e.canInsertTextAfter()||!o.canInsertTextAfter()&&!e.isComposing()||r)}(t,a)}function Rn$1(t,e){return Ls(t)&&null!==t.nodeValue&&0!==e&&e!==t.nodeValue.length}function Wn$1(e,n,o){const{anchorNode:r,anchorOffset:i,focusNode:s,focusOffset:l}=tc(e,n._rootElement),c=n._inputState;c.isSelectionChangeFromDOMUpdate&&(c.isSelectionChangeFromDOMUpdate=false,Rn$1(r,i)&&Rn$1(s,l)&&!c.postDeleteSelectionToRestore)||Mi(n,()=>{if(!o)return void el(null);if(!Os(n,r,s))return;let a=Lr();if(c.postDeleteSelectionToRestore&&cr(a)&&a.isCollapsed()){const t=a.anchor,e=c.postDeleteSelectionToRestore.anchor;(t.key===e.key&&t.offset===e.offset+1||1===t.offset&&e.getNode().is(t.getNode().getPreviousSibling()))&&(a=c.postDeleteSelectionToRestore.clone(),el(a));}if(c.postDeleteSelectionToRestore=null,cr(a)){const o=a.anchor,u=o.getNode();if(a.isCollapsed()){"Range"===e.type&&r===s&&(a.dirty=true);const i=Pl(n).event,l=i?i.timeStamp:performance.now(),{format:f,style:d,offset:h,key:g,timeStamp:_}=c.collapsedSelectionFormat,p=tl(),y=false===n.isComposing()&&""===p.getTextContent();if(l<_+200&&o.offset===h&&o.key===g)Un$1(a,f,d);else if("text"===o.type)Xo(u)||t(141),$n$1(a,u);else if("element"===o.type&&!y){Pi(u)||t(259);const e=o.getNode();e.isEmpty()?function(t,e){const n=e.getTextFormat(),o=e.getTextStyle();Un$1(t,n,o);}(a,e):Un$1(a,a.format,"");}}else {const t=o.key,e=a.focus.key,n=a.getNodes(),r=n.length,s=a.isBackward(),c=s?l:i,u=s?i:l,f=s?e:t,d=s?t:e;let h=2047,g=false;for(let t=0;t<r;t++){const e=n[t],o=e.getTextContentSize();if(Xo(e)&&0!==o&&!(0===t&&e.__key===f&&c===o||t===r-1&&e.__key===d&&0===u)&&(g=true,h&=e.getFormat(),0===h))break}a.format=g?h:0;}}kl(n,Ie$2,void 0);});}function Un$1(t,e,n){t.format===e&&t.style===n||(t.format=e,t.style=n,t.dirty=true);}function $n$1(t,e){Un$1(t,e.getFormat(),e.getStyle());}function Hn$1(t){if(!t.getTargetRanges)return null;const e=t.getTargetRanges();return 0===e.length?null:e[0]}function Jn$1(t){const{lastKeyCode:e}=gi()._inputState;if(null==t||t.length<=1||null==e)return;const n=1===e.length?e:"Enter"===e?"\n":"Tab"===e?"\t":null;if(!n)return;const o=Lr();if(!cr(o)||!o.isCollapsed())return;const r=o.anchor.getNode();if(!Xo(r))return;const{offset:i}=o.anchor;if(r.getTextContentSize()===i){const t=r.getNextSibling();if("\n"===n){if(qi(t))t.selectEnd();else if(!t){const t=Jc(r,Mr),e=t&&t.getNextSibling();Pi(e)&&e.selectStart();}}else "\t"===n?er(t)&&t.selectEnd():Xo(t)&&t.getTextContent()[0]===n&&t.select(1,1);}else r.getTextContent()[i]===n&&r.select(i+1,i+1);}function jn$1(t){t.isInsertTextAfterHandledSelectionCommand=false,null!==t.handledSelectionCommandTimeoutId&&(clearTimeout(t.handledSelectionCommandTimeoutId),t.handledSelectionCommandTimeoutId=null);}function Vn$1(t){jn$1(t),t.isInsertTextAfterHandledSelectionCommand=true,t.handledSelectionCommandTimeoutId=setTimeout(()=>jn$1(t),0);}function qn$1(t,e){const n=lc(t);if(dc(n)&&zc(n,e))return  true;const o=e.getRootElement();if(null===o)return  false;const r=sc(o.ownerDocument);return null!==r&&o.contains(r)&&zc(r,e)}function Yn$1(e){const n=e.inputType,o=Hn$1(e),r=gi(),i=r._inputState,s=Lr();if("insertText"===n&&e.data&&i.isInsertTextAfterHandledSelectionCommand){if(jn$1(i),e.preventDefault(),cr(s)&&!s.isCollapsed()){const t=s.isBackward()?s.anchor:s.focus;s.anchor.set(t.key,t.offset,t.type),s.focus.set(t.key,t.offset,t.type);}return  true}if("deleteContentBackward"===n){if(null===s){const t=Kr();if(!cr(t))return  true;el(t.clone());}if(cr(s)){const n=s.anchor.key===s.focus.key;if(function(t,e){return "MediaLast"===t.lastKeyCode&&e<t.lastKeyDownTimeStamp+30}(i,e.timeStamp)&&r.isComposing()&&n){if(Js(null),i.lastKeyDownTimeStamp=0,setTimeout(()=>{Mi(r,()=>{Js(null);});},30),cr(s)){const e=s.anchor.getNode();e.markDirty(),Xo(e)||t(142),$n$1(s,e);}}else {if(Js(null),l&&null!==o&&!o.collapsed&&(s.applyDOMRange(o),!s.isCollapsed()))return e.preventDefault(),s.removeText(),true;e.preventDefault();const t=s.anchor.getNode(),c=t.getTextContent(),a=t.canInsertTextAfter(),u=0===s.anchor.offset&&s.focus.offset===c.length;let d=f&&n&&!u&&a;if(d&&s.isCollapsed()&&(d=!Li(vl(s.anchor,true))),!d){kl(r,Ue$2,true);const t=Lr();f&&cr(t)&&t.isCollapsed()&&(i.postDeleteSelectionToRestore=t,setTimeout(()=>i.postDeleteSelectionToRestore=null));}}return  true}}if(!cr(s))return  true;const c=e.data;null!==i.unprocessedBeforeInputData&&cl(false,r,i.unprocessedBeforeInputData),s.dirty&&null===i.unprocessedBeforeInputData||!s.isCollapsed()||zi(s.anchor.getNode())||null===o||s.applyDOMRange(o),i.unprocessedBeforeInputData=null;const a=s.anchor,u=s.focus,d=a.getNode(),h=u.getNode();if("insertText"===n||"insertTranspose"===n){if("\n"===c)e.preventDefault(),kl(r,$e$3,false);else if(c===D$1)e.preventDefault(),kl(r,He$3,void 0);else if(null==c&&e.dataTransfer){const t=e.dataTransfer.getData("text/plain");e.preventDefault(),s.insertRawText(t);}else null!=c&&Bn$1(s,o,c,e.timeStamp,true)?(e.preventDefault(),kl(r,Je$3,c),Jn$1(c)):i.unprocessedBeforeInputData=c;return i.lastBeforeInputInsertTextTimeStamp=e.timeStamp,true}switch(e.preventDefault(),n){case "insertFromYank":case "insertFromDrop":case "insertReplacementText":kl(r,Je$3,e);Jn$1((e.dataTransfer?e.dataTransfer.getData("text/plain"):null)??e.data);break;case "insertFromComposition":{const t=i.hadOrphanedCompositionEvents;i.hadOrphanedCompositionEvents=false;const n=r._compositionKey;Js(null),t||kl(r,Je$3,e),Zn$1(n);break}case "insertLineBreak":Js(null),kl(r,$e$3,false);break;case "insertParagraph":Js(null),i.isInsertLineBreak&&!l?(i.isInsertLineBreak=false,kl(r,$e$3,false)):kl(r,He$3,void 0);break;case "insertFromPaste":case "insertFromPasteAsQuotation":kl(r,je$2,e);break;case "deleteByComposition":(function(t,e){return t!==e||Pi(t)||Pi(e)||!Fs(t)||!Fs(e)})(d,h)&&kl(r,Ve$2,e);break;case "deleteByDrag":Ol(No),kl(r,Ve$2,e);break;case "deleteByCut":kl(r,Ve$2,e);break;case "deleteContent":kl(r,Ue$2,false);break;case "deleteWordBackward":kl(r,qe$3,true);break;case "deleteWordForward":kl(r,qe$3,false);break;case "deleteHardLineBackward":case "deleteSoftLineBackward":kl(r,Ye$1,true);break;case "deleteContentForward":case "deleteHardLineForward":case "deleteSoftLineForward":kl(r,Ye$1,false);break;case "formatStrikeThrough":kl(r,Ge$2,"strikethrough");break;case "formatBold":kl(r,Ge$2,"bold");break;case "formatItalic":kl(r,Ge$2,"italic");break;case "formatUnderline":kl(r,Ge$2,"underline");break;case "historyUndo":kl(r,Qe$2,void 0);break;case "historyRedo":kl(r,Ze$2,void 0);}return  true}function Gn$1(t){const e=gi(),n=e._inputState,o=Lr(),r=t.data,l=Hn$1(t);let c=false;if(null!=r&&cr(o)){const a=Hl(Pl(e)),u=null!==a?tc(a,e._rootElement):null,d="insertCompositionText"===t.inputType&&"ending-firefox"!==n.compositionPhase&&!e.isComposing();d&&(n.hadOrphanedCompositionEvents=true);const h=o.anchor.getNode(),g="insertCompositionText"===t.inputType&&"ending-firefox"!==n.compositionPhase&&e.isComposing()&&Xo(h)&&Is(h);if(!d&&!g&&Bn$1(o,l,r,t.timeStamp,false,u)){if(c=true,"ending-firefox"===n.compositionPhase){const t=to$1(e,r);if(n.compositionPhase="idle",t)return Ol(Eo),nl(),true}const l=o.anchor.getNode();if(null===a||null===u)return  true;const d=o.isBackward(),h=d?o.anchor.offset:o.focus.offset,g=d?o.focus.offset:o.anchor.offset;s&&!o.isCollapsed()&&Xo(l)&&null!==u.anchorNode&&l.getTextContent().slice(0,h)+r+l.getTextContent().slice(h+g)===ll(u.anchorNode)||kl(e,Je$3,r);const _=r.length;i&&_>1&&"insertCompositionText"===t.inputType&&!e.isComposing()&&(o.anchor.offset-=_,o._cachedNodes=null,o._cachedIsBackward=null),f&&e.isComposing()&&(n.lastKeyDownTimeStamp=0,Js(null));}}if(!c){cl(false,e,null!==r?r:void 0),"ending-firefox"===n.compositionPhase&&(to$1(e,r||void 0),Ol(Eo),n.compositionPhase="idle");}return nl(),true}function Xn$1(t){const e=gi(),n=e._inputState,o=Lr();if(cr(o)&&!e.isComposing()){n.compositionPhase="composing",n.hadOrphanedCompositionEvents=false;const r=o.anchor,i=o.anchor.getNode();if(Js(r.key),Ol(wo),t.timeStamp<n.lastKeyDownTimeStamp+30||"element"===r.type||!o.isCollapsed()||!f&&(i.getFormat()!==o.format||Xo(i)&&i.getStyle()!==o.style)||Xo(i)&&(Is(i)||0===r.offset&&!i.canInsertTextBefore()||r.offset===i.getTextContentSize()&&!i.canInsertTextAfter())){kl(e,Je$3,P);const t=Lr();cr(t)&&Js(t.anchor.key);}}return  true}function Qn(t){const e=gi();return e._inputState.compositionPhase="idle",to$1(e,t.data),Ol(Eo),true}function Zn$1(t){if(null===t)return;const e=Vs(t);if(!Xo(e)||"text"===e.getType()||Is(e)||!e.isAttached())return;const n=Lr(),o=cr(n)&&n.anchor.key===t?n.anchor.offset:null,r=Go(e.getTextContent());if(r.setFormat(e.getFormat()),r.setStyle(e.getStyle()),e.replace(r),null!==o){const t=Math.min(o,r.getTextContentSize());r.select(t,t);}}function to$1(t,e){const n=t._compositionKey;if(Js(null),null!==n&&null!=e){if(""===e){const e=Vs(n),o=t.getElementByKey(n),r=null!==o&&Xo(e)?Nc(e,o,t):null;if(null!==r&&null!==r.nodeValue&&Xo(e)){const n=Hl(Pl(t)),o=n&&tc(n,t._rootElement);let i=null,s=null;null!==o&&o.anchorNode===r&&(i=o.anchorOffset,s=o.focusOffset),al(e,r.nodeValue,i,s,true);}return Zn$1(n),false}if("\n"===e[e.length-1]){const e=Lr();if(cr(e)||ur(e)){if(cr(e)){const t=e.focus;e.anchor.set(t.key,t.offset,t.type);}return kl(t,cn$1,null),Zn$1(n),false}}const o=Vs(n);if(null!==o&&Xo(o)&&Is(o)){o.markDirty();const t=Lr(),r=o.getTextContentSize(),i=cr(t)&&t.anchor.key===n?t.anchor.offset:r;return o.select(i,i).insertText(e),true}}return cl(true,t,e),Zn$1(n),false}function eo$1(t){const e=gi(),n=e._inputState;if(null==t.key)return  true;if("ending-safari"===n.compositionPhase){const o=pl(t);if(o&&Mi(e,()=>{to$1(e,n.compositionEndData);}),n.compositionPhase="idle",n.compositionEndData="",o)return  true}if(function(t){return hl(t,"ArrowRight",{shiftKey:"any"})}(t))kl(e,en$3,t);else if(function(t){return hl(t,"ArrowRight",{...gl,shiftKey:"any"})}(t))kl(e,nn$2,t);else if(function(t){return hl(t,"ArrowLeft",{shiftKey:"any"})}(t))kl(e,on$2,t);else if(function(t){return hl(t,"ArrowLeft",{...gl,shiftKey:"any"})}(t))kl(e,rn$2,t);else if(function(t){return hl(t,"ArrowUp",{altKey:"any",shiftKey:"any"})}(t))kl(e,sn$2,t);else if(function(t){return hl(t,"ArrowDown",{altKey:"any",shiftKey:"any"})}(t))kl(e,ln$2,t);else if(function(t){return hl(t,"Enter",{altKey:"any",ctrlKey:"any",metaKey:"any",shiftKey:true})}(t))n.isInsertLineBreak=true,kl(e,cn$1,t);else if(function(t){return " "===t.key}(t))kl(e,an$1,t);else if(function(t){return r&&hl(t,"o",{ctrlKey:true})}(t))t.preventDefault(),n.isInsertLineBreak=true,kl(e,$e$3,true);else if(function(t){return hl(t,"Enter",{altKey:"any",ctrlKey:"any",metaKey:"any"})}(t))n.isInsertLineBreak=false,kl(e,cn$1,t);else if(function(t){return hl(t,"Backspace",{shiftKey:"any"})||r&&hl(t,"h",{ctrlKey:true})}(t))pl(t)?kl(e,un$2,t)&&Vn$1(n):(t.preventDefault(),kl(e,Ue$2,true));else if(function(t){return "Escape"===t.key}(t))kl(e,fn$2,t);else if(function(t){return hl(t,"Delete",{})||r&&hl(t,"d",{ctrlKey:true})}(t))!function(t){return "Delete"===t.key}(t)?(t.preventDefault(),kl(e,Ue$2,false)):kl(e,dn$2,t);else if(function(t){return hl(t,"Backspace",_l)}(t))t.preventDefault(),kl(e,qe$3,true);else if(function(t){return hl(t,"Delete",_l)}(t))t.preventDefault(),kl(e,qe$3,false);else if(function(t){return r&&hl(t,"Backspace",{metaKey:true})}(t))t.preventDefault(),kl(e,Ye$1,true);else if(function(t){return r&&(hl(t,"Delete",{metaKey:true})||hl(t,"k",{ctrlKey:true}))}(t))t.preventDefault(),kl(e,Ye$1,false);else if(function(t){return hl(t,"b",gl)}(t))t.preventDefault(),kl(e,Ge$2,"bold");else if(function(t){return hl(t,"u",gl)}(t))t.preventDefault(),kl(e,Ge$2,"underline");else if(function(t){return hl(t,"i",gl)}(t))t.preventDefault(),kl(e,Ge$2,"italic");else if(function(t){return hl(t,"Tab",{shiftKey:"any"})}(t))kl(e,hn$2,t);else if(function(t){return hl(t,"z",gl)}(t))t.preventDefault(),kl(e,Qe$2,void 0);else if(function(t){if(r)return hl(t,"z",{metaKey:true,shiftKey:true});return hl(t,"y",{ctrlKey:true})||hl(t,"z",{ctrlKey:true,shiftKey:true})}(t))t.preventDefault(),kl(e,Ze$2,void 0);else {const o=e._editorState._selection;!function(t){return hl(t,"a",gl)}(t)?null===o||cr(o)||(!function(t){return hl(t,"c",gl)}(t)?function(t){return hl(t,"x",gl)}(t)&&(t.preventDefault(),kl(e,Tn$1,t)):(t.preventDefault(),kl(e,vn$1,t))):(t.preventDefault(),kl(e,kn$2,t)&&Vn$1(n));}return function(t){return t.ctrlKey||t.shiftKey||t.altKey||t.metaKey}(t)&&e.dispatchCommand(An$1,t),true}function no$1(t){let e=t.__lexicalEventHandles;return void 0===e&&(e=[],t.__lexicalEventHandles=e),e}const oo$1=new Map;function ro$1(t){const e=Jl(t.target);if(null===e)return;const n=wl(t.target);let o=null,r=null;const i=null!==n?Kn$1.get(n):void 0;if(null!==n){if(void 0!==i){const t=i.editors;let n=i.hasShadowEditor;if(void 0===n){n=false;for(const e of t)if(null!==e._rootElement&&jl(e._rootElement.getRootNode())){n=true;break}i.hasShadowEditor=n;}if(n){let n=null,i=null;for(const s of t){const t=s._rootElement;if(null===t)continue;const l=tc(e,t).anchorNode;if(null!==l&&As(l)===s){if(jl(t.getRootNode())){o=s,r=l;break}null===n&&(n=s,i=l);}}null===o&&null!==n&&(o=n,r=i);}else {const t=e.anchorNode;null===t||dc(t)&&null!==t.shadowRoot||(o=As(t),null!==o&&(r=t));}}if(null===o){const t=sc(n);o=null!==t?As(t):null;}}if(null===o)return;if(o._inputState.isSelectionChangeFromMouseDown){if(void 0!==i)for(const t of i.editors)t._inputState.isSelectionChangeFromMouseDown=false;Mi(o,()=>{const n=Kr(),i=r??tc(e,o._rootElement).anchorNode;if(dc(i)||Ls(i)){el(Ir(n,e,o,t));}});}const s=il(o),l=s[s.length-1],c=l._key,a=oo$1.get(c),u=a||l;u!==o&&Wn$1(e,u,false),Wn$1(e,o,true),o!==l?oo$1.set(c,o):a&&oo$1.delete(c);}function io$1(t){t._lexicalHandled=true;}function so$1(t){return  true===t._lexicalHandled}function co$1(e){const n=Ln$1.get(e);if(void 0===n)return void 0;const o=Kn$1.get(n);if(void 0===o)return void 0;Ln$1.delete(e);const r=Ds(e);Ms(r)?(!function(t){if(null!==t._parentEditor){const e=il(t),n=e[e.length-1]._key;oo$1.get(n)===t&&oo$1.delete(n);}else oo$1.delete(t._key);}(r),o.editors.delete(r),o.hasShadowEditor=void 0,e.__lexicalEditor=null):r&&t(198);const i=no$1(e);for(let t=0;t<i.length;t++)i[t]();e.__lexicalEventHandles=[];}function ao(e,n,o){ui();const r=e.__key,i=e.getParent();if(null===i)return void(null!==Xc(e)&&t(367,r,String(Xc(e))));const s=function(t){const e=Lr();if(!cr(e)||!Pi(t))return e;const{anchor:n,focus:o}=e,r=n.getNode(),i=o.getNode();Al(r,t)&&n.set(t.__key,0,"element");Al(i,t)&&o.set(t.__key,0,"element");return e}(e);let l=false;if(cr(s)&&n){const t=s.anchor,n=s.focus;t.key===r&&(Rr(t,e,i,e.getPreviousSibling(),e.getNextSibling()),l=true),n.key===r&&(Rr(n,e,i,e.getPreviousSibling(),e.getNextSibling()),l=true);}else ur(s)&&n&&e.isSelected()&&e.selectPrevious();if(cr(s)&&n&&!l){const t=e.getIndexWithinParent();Us(e),zr(s,i,t,-1);}else Us(e);o||Kl(i)||i.canBeEmpty()||!i.isEmpty()||ao(i,n),n&&s&&zi(i)&&i.isEmpty()&&i.selectEnd();}function uo$1(t){return t}const fo=Symbol.for("ephemeral");function ho$1(t){return t[fo]||false}const go={configurable:true,enumerable:false,value:void 0,writable:true};class _o{__type;__key;__parent;__prev;__next;__state;[Kt$4];static getType(){const{ownNodeType:e}=Wc(this);return void 0===e&&t(64,this.name),e}static clone(e){t(65,this.name);}$config(){return {}}config(t,e){const n=e.extends||Vc(this.constructor);return Object.assign(e,{extends:n}),"string"==typeof t&&Object.assign(e,{type:t}),{[t]:e}}afterCloneFrom(t){this.__key===t.__key?(this.__parent=t.__parent,this.__next=t.__next,this.__prev=t.__prev,this.__state=t.__state):t.__state&&(this.__state=t.__state.getWritable(this));}resetOnCopyNodeFrom(t){this.__state&&(this.__state=this.__state.getWritable(this).resetOnCopyNode());}static importDOM;constructor(t){this.__type=this.constructor.getType(),this.__parent=null,this.__prev=null,this.__next=null,Object.defineProperty(this,"__state",go),Object.defineProperty(this,Kt$4,go),Ws(this,t);}getType(){return this.__type}isInline(){t(137,this.constructor.name);}isAttached(){let t=this.__key;for(;null!==t;){if("root"===t)return  true;const e=Vs(t);if(null===e)break;t=null!==e.__parent?e.__parent:Xc(e);}return  false}isSelected(t){const e=t||Lr();if(null==e)return  false;const n=e.getNodes().some(t=>t.__key===this.__key);if(Xo(this))return n;if(cr(e)&&"element"===e.anchor.type&&"element"===e.focus.type){if(e.isCollapsed())return  false;const t=this.getParent();if(Li(this)&&this.isInline()&&t){const n=e.isBackward()?e.focus:e.anchor;if(t.is(n.getNode())&&n.offset===t.getChildrenSize()&&this.is(t.getLastChild()))return  false}}return n}getKey(){return this.__key}getIndexWithinParent(){const t=this.getParent();if(null===t)return  -1;let e=t.getFirstChild(),n=0;for(;null!==e;){if(this.is(e))return n;n++,e=e.getNextSibling();}return  -1}getParent(){const t=this.getLatest().__parent;return null===t?null:Vs(t)}getParentOrThrow(){const e=this.getParent();return null===e&&t(66,this.__key),e}getTopLevelElement(){let e=this;for(;null!==e;){const n=e.getParent();if(Kl(n)||null!==Xc(e))return Pi(e)||e===this&&Li(e)||t(194),e;e=n;}return null}getTopLevelElementOrThrow(){const e=this.getTopLevelElement();return null===e&&t(67,this.__key),e}getParents(){const t=[];let e=this.getParent();for(;null!==e;)t.push(e),e=e.getParent();return t}getParentKeys(){const t=[];let e=this.getParent();for(;null!==e;)t.push(e.__key),e=e.getParent();return t}getPreviousSibling(){const t=this.getLatest().__prev;return null===t?null:Vs(t)}getPreviousSiblings(){const t=[],e=this.getParent();if(null===e)return t;let n=e.getFirstChild();for(;null!==n&&!n.is(this);)t.push(n),n=n.getNextSibling();return t}getNextSibling(){const t=this.getLatest().__next;return null===t?null:Vs(t)}getNextSiblings(){const t=[];let e=this.getNextSibling();for(;null!==e;)t.push(e),e=e.getNextSibling();return t}getCommonAncestor(t){const e=Pi(this)?this:this.getParent(),n=Pi(t)?t:t.getParent(),o=e&&n?Ga(e,n):null;return o?o.commonAncestor:null}is(t){return null!=t&&this.__key===t.__key}isBefore(e){const n=Ga(this,e);return null!==n&&("descendant"===n.type||("branch"===n.type?-1===Va(n):("same"!==n.type&&"ancestor"!==n.type&&t(279),false)))}isParentOf(t){return Al(t,this)}getNodesBetween(e){const n=this.isBefore(e),o=[],r=new Set;let i=this;for(;null!==i;){const s=i.__key;if(r.has(s)||(r.add(s),o.push(i)),i===e)break;const l=Pi(i)?n?i.getFirstChild():i.getLastChild():null;if(null!==l){i=l;continue}const c=n?i.getNextSibling():i.getPreviousSibling();if(null!==c){i=c;continue}const a=i.getParentOrThrow();if(r.has(a.__key)||o.push(a),a===e)break;let u=null,f=a;do{if(null===f&&t(68),u=n?f.getNextSibling():f.getPreviousSibling(),f=f.getParent(),null===f)break;null!==u||r.has(f.__key)||o.push(f);}while(null===u);i=u;}return n||o.reverse(),o}isDirty(){const t=gi()._dirtyLeaves;return null!==t&&t.has(this.__key)}getLatest(){if(ho$1(this))return this;const e=Vs(this.__key);return null===e&&t(113),e}getWritable(){if(ho$1(this))return this;ui();const t=di(),e=gi(),n=t._nodeMap,o=this.__key,r=this.getLatest(),i=e._cloneNotNeeded,s=Lr();if(null!==s&&s.setCachedNodes(null),i.has(o))return Hs(r),r;const l=Mc(r);return i.add(o),Hs(l),n.set(o,l),l}getTextContent(){return ua(this)}getTextContentSize(){return this.getTextContent().length}createDOM(e,n){t(70);}updateDOM(e,n,o){t(71);}getDOMSlot(t){return new q$1(t)}exportDOM(t){return {element:this.createDOM(t._config,t)}}exportJSON(){const t=this.__state?this.__state.toJSON():void 0;return {type:this.__type,version:1,...t}}static importJSON(e){t(18,this.name);}updateFromJSON(t){return function(t,e){const n=t.getWritable(),o=e.$;let r=o;for(const t of Nt$5(n).flatKeys)t in e&&(void 0!==r&&r!==o||(r={...o}),r[t]=e[t]);return (n.__state||r)&&bt$3(t).updateFromJSON(r),n}(this,t)}static transform(){return null}remove(t){ao(this,true,t);}replace(e,n){ui();let o=Lr();null!==o&&(o=o.clone()),Rl(this,e);const r=this.getLatest(),i=this.__key,s=e.__key,l=e.getWritable(),c=this.getParentOrThrow().getWritable(),a=c.__size,u=l.getParent(),f=null!==u?l.getIndexWithinParent():-1;Us(l),null!==u&&cr(o)&&zr(o,u,f,-1);const d=r.getPreviousSibling(),h=r.getNextSibling(),g=r.__prev,_=r.__next,p=r.__parent;if(ao(r,false,true),null===d)c.__first=s;else {d.getWritable().__next=s;}if(l.__prev=g,null===h)c.__last=s;else {h.getWritable().__prev=s;}l.__next=_,l.__parent=p,c.__size=a;let y=0;n&&(Pi(this)&&Pi(l)||t(139),y=l.getChildrenSize(),l.splice(y,0,this.getChildren()));const m=na(this);if(m.length>0){Yc(this)&&Yc(l)||t(368,this.__key,l.__key);for(const t of m){const e=oa(this,t);null!==e&&(ga(this,t),ha(l,t,e));}}if(cr(o)){el(o);const t=o.anchor,e=o.focus;t.key===i&&(n&&"element"===t.type?t.set(l.__key,y+t.offset,"element"):ir(t,l)),e.key===i&&(n&&"element"===e.type?e.set(l.__key,y+e.offset,"element"):ir(e,l));}return js()===i&&Js(s),l}insertAfter(t,e=true){ui(),Rl(this,t);const n=this.getWritable(),o=t.getWritable();_a(this.getParentOrThrow());const r=o.getParent(),i=Lr();let s=false,l=false;if(null!==r){const n=t.getIndexWithinParent();if(cr(i)){const t=r.__key,e=i.anchor,o=i.focus;s="element"===e.type&&e.key===t&&e.offset===n+1,l="element"===o.type&&o.key===t&&o.offset===n+1;}Us(o),e&&cr(i)&&zr(i,r,n,-1);}else Us(o);const c=this.getNextSibling(),a=this.getParentOrThrow().getWritable(),u=o.__key,f=n.__next;if(null===c)a.__last=u;else {c.getWritable().__prev=u;}if(a.__size++,n.__next=u,o.__next=f,o.__prev=n.__key,o.__parent=n.__parent,e&&cr(i)){const t=this.getIndexWithinParent();zr(i,a,t+1);const e=a.__key;s&&i.anchor.set(e,t+2,"element"),l&&i.focus.set(e,t+2,"element");}return t}insertBefore(t,e=true){ui(),Rl(this,t);const n=this.getWritable(),o=t.getWritable();_a(this.getParentOrThrow());const r=o.__key,i=Lr(),s=o.getParent(),l=null!==s?o.getIndexWithinParent():-1;Us(o),null!==s&&e&&cr(i)&&zr(i,s,l,-1);const c=this.getPreviousSibling(),a=this.getParentOrThrow().getWritable(),u=n.__prev,f=this.getIndexWithinParent();if(null===c)a.__first=r;else {c.getWritable().__next=r;}if(a.__size++,n.__prev=r,o.__prev=u,o.__next=n.__key,o.__parent=n.__parent,e&&cr(i)){zr(i,this.getParentOrThrow(),f);}return t}isParentRequired(){return  false}createParentElementNode(){return ts()}selectStart(){return this.selectPrevious()}selectEnd(){return this.selectNext(0,0)}selectPrevious(t,e){ui();const n=Qc(this);if(null!==n)return n.selectPrevious(t,e);const o=this.getPreviousSibling(),r=this.getParentOrThrow();if(null===o)return r.select(0,0);if(Pi(o))return o.select();if(!Xo(o)){const t=o.getIndexWithinParent()+1;return r.select(t,t)}return o.select(t,e)}selectNext(t,e){ui();const n=Qc(this);if(null!==n)return n.selectNext(t,e);const o=this.getNextSibling(),r=this.getParentOrThrow();if(null===o)return r.select();if(Pi(o))return o.select(0,0);if(!Xo(o)){const t=o.getIndexWithinParent();return r.select(t,t)}return o.select(t,e)}markDirty(){this.getWritable();}reconcileObservedMutation(t,e){this.markDirty();}}function po(t){return t instanceof _o}const yo="historic",mo="history-push",xo="history-merge",Co="paste",So="cut",vo="collaboration",ko="skip-scroll-into-view",bo="skip-dom-selection",No="skip-selection-focus",wo="composition-start",Eo="composition-end",Oo="!important";function Mo(t){const e={};if(!t)return e;let n="",o="",r=null,i=false,s=false,l=false,c=0;const a=t.length;let u=-1;for(let f=0;f<a;f++){const a=t[f];if(i)"*"===a&&"/"===t[f+1]&&(i=false,f++);else if(s) -1===u&&(u=f),s=false;else if(null===r)if("/"!==a||"*"!==t[f+1])if('"'!==a&&"'"!==a)if("("!==a)if(")"!==a)if(l||":"!==a||0!==c){if(";"===a&&0===c){ -1!==u&&(l?o+=t.slice(u,f):n+=t.slice(u,f),u=-1);const r=n.trim(),i=o.trim();""!==r&&""!==i&&(e[r]=i),n="",o="",l=false;continue} -1===u&&(u=f);}else  -1!==u&&(n+=t.slice(u,f),u=-1),l=true;else  -1===u&&(u=f),c=Math.max(0,c-1);else  -1===u&&(u=f),c++;else  -1===u&&(u=f),r=a;else  -1!==u&&(l?o+=t.slice(u,f):n+=t.slice(u,f),u=-1),i=true,f++;else  -1===u&&(u=f),"\\"===a?s=true:a===r&&(r=null);} -1!==u&&(l?o+=t.slice(u,a):n+=t.slice(u,a));const f=n.trim(),d=o.trim();return ""!==f&&""!==d&&(e[f]=d),e}function Ao(t,e,n){const o=n.trimEnd(),r=o.length-10;r>=0&&o.slice(r).toLowerCase()===Oo?t.setProperty(e,o.slice(0,r).trim(),"important"):t.setProperty(e,n,"");}function Po(t,e,n=""){if(e===n)return;const o=Mo(n),r=Mo(e);for(const e in r)delete o[e],Ao(t,e,r[e]);for(const e in o)t.removeProperty(e);}function Fo(t,e){return 16&e?"code":e&T?"mark":32&e?"sub":64&e?"sup":null}function Io(t,e){return 1&e?"strong":2&e?"em":"span"}function Lo(t,e,n,o,r){const i=o.classList;let s=ml(r,"base");void 0!==s&&i.add(...s),s=ml(r,"underlineStrikethrough");let l=false;const c=8&e&&4&e;void 0!==s&&(8&n&&4&n?(l=true,c||i.add(...s)):c&&i.remove(...s));for(const t in z$1){const o=z$1[t];if(s=ml(r,t),void 0!==s)if(n&o){if(l&&("underline"===t||"strikethrough"===t)){e&o&&i.remove(...s);continue}(0===(e&o)||c&&"underline"===t||"strikethrough"===t)&&i.add(...s);}else e&o&&i.remove(...s);}}function Ko(t,e,n){const o=n.isComposing(),r=t+(o?A:""),s=Cc(),l=Sc(s).$getDOMSlot(n,e,s),c=l.getFirstChild();if(null===c||c.nodeType!==Node.TEXT_NODE)return void l.insertChild(Xl().createTextNode(r));const a=c,u=a.nodeValue;if(u!==r)if(o||i){const[t,e,n]=function(t,e){const n=t.length,o=e.length;let r=0,i=0;for(;r<n&&r<o&&t[r]===e[r];)r++;for(;i+r<n&&i+r<o&&t[n-i-1]===e[o-i-1];)i++;return [r,n-r-i,e.slice(r,o-i)]}(u,r);0!==e&&a.deleteData(t,e),a.insertData(t,n);}else a.nodeValue=r;}function zo(t,e,n,o,r,i){Ko(r,t,e);const s=i.theme.text;void 0!==s&&Lo(0,0,o,t,s);}function Bo(t,e){const n=Xl().createElement(e);return n.appendChild(t),n}function Ro(t){return null!=t&&true===t.__isInlineFormattable}class Wo extends _o{__text;__format;__style;__mode;__detail;get __isInlineFormattable(){return  true}static getType(){return "text"}static clone(t){return new Wo(t.__text,t.__key)}afterCloneFrom(t){super.afterCloneFrom(t),this.__text=t.__text,this.__format=t.__format,this.__style=t.__style,this.__mode=t.__mode,this.__detail=t.__detail;}constructor(t="",e){super(e),this.__text=t,this.__format=0,this.__style="",this.__mode=0,this.__detail=0;}getFormat(){return this.getLatest().__format}getDetail(){return this.getLatest().__detail}getMode(){const t=this.getLatest();return $[t.__mode]}getStyle(){return this.getLatest().__style}isToken(){return 1===this.getLatest().__mode}isComposing(){return this.__key===js()}isSegmented(){return 2===this.getLatest().__mode}isDirectionless(){return !!(1&this.getLatest().__detail)}isUnmergeable(){return !!(2&this.getLatest().__detail)}hasFormat(t){const e=z$1[t];return 0!==(this.getFormat()&e)}isSimpleText(){return "text"===this.__type&&0===this.__mode}getTextContent(){return this.getLatest().__text}getFormatFlags(t,e){return Bs(this.getLatest().__format,t,e)}canHaveFormat(){return  true}isInline(){return  true}createDOM(t,e){const n=this.__format,o=Fo(0,n),r=Io(0,n),i=null===o?r:o,s=Xl().createElement(i);let l=s;this.hasFormat("code")&&s.setAttribute("spellcheck","false"),null!==o&&(l=Xl().createElement(r),s.appendChild(l));zo(l,this,0,n,this.__text,t);const c=this.__style;return ""!==c&&Po(s.style,c),s}updateDOM(e,n,o){const r=this.__text,i=e.__format,s=this.__format,l=Fo(0,i),c=Fo(0,s),a=Io(0,i),u=Io(0,s);if((null===l?a:l)!==(null===c?u:c))return  true;if(l===c&&a!==u){const e=n.firstChild;null==e&&t(48);const i=Xl().createElement(u);return zo(i,this,0,s,r,o),n.replaceChild(i,e),false}let f=n;null!==c&&null!==l&&(f=n.firstChild,null==f&&t(49)),Ko(r,f,this);const d=o.theme.text;void 0!==d&&i!==s&&Lo(0,i,s,f,d);const h=e.__style,g=this.__style;return h!==g&&Po(n.style,g,h),false}static importDOM(){return {"#text":()=>({conversion:jo,priority:0}),b:()=>({conversion:$o,priority:0}),code:()=>({conversion:Yo,priority:0}),em:()=>({conversion:Yo,priority:0}),i:()=>({conversion:Yo,priority:0}),mark:()=>({conversion:Yo,priority:0}),s:()=>({conversion:Yo,priority:0}),span:()=>({conversion:Uo,priority:0}),strong:()=>({conversion:Yo,priority:0}),sub:()=>({conversion:Yo,priority:0}),sup:()=>({conversion:Yo,priority:0}),u:()=>({conversion:Yo,priority:0})}}static importJSON(t){return Go().updateFromJSON(t)}updateFromJSON(t){return super.updateFromJSON(t).setTextContent(t.text).setFormat(t.format).setDetail(t.detail).setMode(t.mode).setStyle(t.style)}exportDOM(e){let{element:n}=super.exportDOM(e);return dc(n)||t(132),n.style.whiteSpace="pre-wrap",this.hasFormat("lowercase")?n.style.textTransform="lowercase":this.hasFormat("uppercase")?n.style.textTransform="uppercase":this.hasFormat("capitalize")&&(n.style.textTransform="capitalize"),this.hasFormat("bold")&&(n=Bo(n,"b")),this.hasFormat("italic")&&(n=Bo(n,"i")),this.hasFormat("strikethrough")&&(n=Bo(n,"s")),this.hasFormat("underline")&&(n=Bo(n,"u")),{element:n}}exportJSON(){return {detail:this.getDetail(),format:this.getFormat(),mode:this.getMode(),style:this.getStyle(),text:this.getTextContent(),...super.exportJSON()}}selectionTransform(t,e){}setFormat(t){const e=this.getWritable();return e.__format="string"==typeof t?z$1[t]:t,e}setDetail(t){const e=this.getWritable();return e.__detail="string"==typeof t?B[t]:t,e}setStyle(t){const e=this.getWritable();return e.__style=t,e}toggleFormat(t){const e=Bs(this.getFormat(),t,null);return this.setFormat(e)}toggleDirectionless(){const t=this.getWritable();return t.__detail^=1,t}toggleUnmergeable(){const t=this.getWritable();return t.__detail^=2,t}setMode(t){const e=U[t];if(this.__mode===e)return this;const n=this.getWritable();return n.__mode=e,n}setTextContent(t){if(this.__text===t)return this;const e=this.getWritable();return e.__text=t,e}select(t,e){ui();let n=t,o=e;const r=Lr(),i=this.getTextContent(),s=this.__key;if("string"==typeof i){const t=i.length;void 0===n&&(n=t),void 0===o&&(o=t);}else n=0,o=0;if(!cr(r))return Ar(s,n,s,o,"text","text");{const t=js();t!==r.anchor.key&&t!==r.focus.key||Js(s),r.setTextNodeRange(this,n,this,o);}return r}selectStart(){return this.select(0,0)}selectEnd(){const t=this.getTextContentSize();return this.select(t,t)}spliceText(t,e,n,o){const r=this.getWritable(),i=r.__text,s=n.length;let l=t;l<0&&(l=s+l,l<0&&(l=0));const c=Lr();if(o&&cr(c)){const e=t+s;c.setTextNodeRange(r,e,r,e);}const a=i.slice(0,l)+n+i.slice(l+e);return r.__text=a,r}canInsertTextBefore(){return  true}canInsertTextAfter(){return  true}splitText(...t){ui();const e=this.getLatest(),n=e.getTextContent();if(""===n)return [];const o=e.__key,r=js(),i=n.length;t.sort((t,e)=>t-e),t.push(i);const s=[],l=t.length;for(let e=0,o=0;e<i&&o<=l;o++){const r=t[o];r>e&&(s.push(n.slice(e,r)),e=r);}const c=s.length;if(1===c)return [e];const a=s[0],u=e.getParent();let f;const d=e.getFormat(),h=e.getStyle(),g=e.__detail;let _=false,p=null,y=null;const m=Lr();if(cr(m)){const[t,e]=m.isBackward()?[m.focus,m.anchor]:[m.anchor,m.focus];"text"===t.type&&t.key===o&&(p=t),"text"===e.type&&e.key===o&&(y=e);}e.isSegmented()?(f=Go(a),f.__format=d,f.__style=h,f.__detail=g,f.__state=At$4(e,f),_=true):f=e.setTextContent(a);const x=[f];for(let t=1;t<c;t++){const n=Go(s[t]);n.__format=d,n.__style=h,n.__detail=g,n.__state=At$4(e,n);const i=n.__key;r===o&&Js(i),x.push(n);}const C=p?p.offset:null,S=y?y.offset:null;let v=0;for(const t of x){if(!p&&!y)break;const e=v+t.getTextContentSize();if(null!==p&&null!==C&&C<=e&&C>=v&&(p.set(t.getKey(),C-v,"text"),C<e&&(p=null)),null!==y&&null!==S&&S<=e&&S>=v){y.set(t.getKey(),S-v,"text");break}v=e;}if(null!==u){!function(t){const e=t.getPreviousSibling(),n=t.getNextSibling();null!==e&&Hs(e);null!==n&&Hs(n);}(this);const t=u.getWritable(),e=this.getIndexWithinParent();_?(t.splice(e,0,x),this.remove()):t.splice(e,1,x),cr(m)&&zr(m,u,e,c-1);}return x}mergeWithSibling(e){const n=e===this.getPreviousSibling();n||e===this.getNextSibling()||t(50);const o=this.__key,r=e.__key,i=this.__text,s=i.length;js()===r&&Js(o);const l=Lr();if(cr(l)){const t=l.anchor,i=l.focus;null!==t&&t.key===r&&Wr(t,n,o,e,s),null!==i&&i.key===r&&Wr(i,n,o,e,s);}const c=e.__text,a=n?c+i:i+c;this.setTextContent(a);const u=this.getWritable();return e.remove(),u}isTextEntity(){return  false}}function Uo(t){return {forChild:Qo(t.style),node:null}}function $o(t){const e=t,n="normal"===e.style.fontWeight;return {forChild:Qo(e.style,n?void 0:"bold"),node:null}}const Ho=new WeakMap;function Jo(t){if(!dc(t))return  false;if("PRE"===t.nodeName)return  true;const e=t.style.whiteSpace;return "string"==typeof e&&e.startsWith("pre")}function jo(e){const n=e;null===e.parentElement&&t(129);let o=n.textContent||"";if(null!==function(t){let e,n=t.parentNode;const o=[t];for(;null!==n&&void 0===(e=Ho.get(n))&&!Jo(n);)o.push(n),n=n.parentNode;const r=void 0===e?n:e;for(let t=0;t<o.length;t++)Ho.set(o[t],r);return r}(n))return {node:Vr(o)};if(o=o.replace(/\r/g,"").replace(/[ \t\n]+/g," "),""===o)return {node:null};if(" "===o[0]){let t=n,e=true;for(;null!==t&&null!==(t=Vo(t,false));){const n=t.textContent||"";if(n.length>0){/[ \t\n]$/.test(n)&&(o=o.slice(1)),e=false;break}}e&&(o=o.slice(1));}if(" "===o[o.length-1]){let t=n,e=true;for(;null!==t&&null!==(t=Vo(t,true));){if((t.textContent||"").replace(/^( |\t|\r?\n)+/,"").length>0){e=false;break}}e&&(o=o.slice(0,o.length-1));}return ""===o?{node:null}:{node:Go(o)}}function Vo(t,e){let n=t;for(;;){let t;for(;null===(t=e?n.nextSibling:n.previousSibling);){const t=n.parentElement;if(null===t)return null;n=t;}if(n=t,dc(n)){const t=n.style.display;if(""===t&&!pc(n)||""!==t&&!t.startsWith("inline"))return null}let o=n;for(;null!==(o=e?n.firstChild:n.lastChild);)n=o;if(Ls(n))return n;if("BR"===n.nodeName)return null}}const qo={code:"code",em:"italic",i:"italic",mark:"highlight",s:"strikethrough",strong:"bold",sub:"subscript",sup:"superscript",u:"underline"};function Yo(t){const e=qo[t.nodeName.toLowerCase()];return void 0===e?{node:null}:{forChild:Qo(t.style,e),node:null}}function Go(t=""){return Bl(new Wo(t))}function Xo(t){return t instanceof Wo}function Qo(t,e){const n=t.fontWeight,o=t.textDecoration.split(" "),r="700"===n||"bold"===n,i=o.includes("line-through"),s="italic"===t.fontStyle,l=o.includes("underline"),c=t.verticalAlign;return t=>Xo(t)||Ro(t)?(r&&!t.hasFormat("bold")&&t.toggleFormat("bold"),i&&!t.hasFormat("strikethrough")&&t.toggleFormat("strikethrough"),s&&!t.hasFormat("italic")&&t.toggleFormat("italic"),l&&!t.hasFormat("underline")&&t.toggleFormat("underline"),"sub"!==c||t.hasFormat("subscript")||t.toggleFormat("subscript"),"super"!==c||t.hasFormat("superscript")||t.toggleFormat("superscript"),e&&!t.hasFormat(e)&&t.toggleFormat(e),t):t}class Zo extends Wo{static getType(){return "tab"}static clone(t){return new Zo(t.__key)}constructor(t){super("\t",t),this.__detail=2;}static importDOM(){return null}createDOM(t){const e=super.createDOM(t),n=ml(t.theme,"tab");if(void 0!==n){e.classList.add(...n);}return e}static importJSON(t){return tr().updateFromJSON(t)}setTextContent(t){return super.setTextContent("\t")}spliceText(e,n,o,r){return ""===o&&0===n||"\t"===o&&1===n||t(286),this}setDetail(e){return 2!==e&&t(127),this}setMode(e){return "normal"!==e&&t(128),this}canInsertTextBefore(){return  false}canInsertTextAfter(){return  false}}function tr(){return Bl(new Zo)}function er(t){return t instanceof Zo}class nr{key;offset;type;_selection;constructor(t,e,n){this._selection=null,this.key=t,this.offset=e,this.type=n;}is(t){return this.key===t.key&&this.offset===t.offset&&this.type===t.type}isBefore(t){if(this.key===t.key)return this.offset<t.offset;return ja(su(Xa(this,"next")),su(Xa(t,"next")))<0}getNode(){const e=Vs(this.key);return null===e&&t(20),e}set(t,e,n,o){const r=this._selection,i=this.key;o&&this.key===t&&this.offset===e&&this.type===n||(this.key=t,this.offset=e,this.type=n,ai()||(js()===i&&Js(t),null!==r&&(r.setCachedNodes(null),cr(r)&&(r._cachedIsBackward=null),r.dirty=true)));}}function or(t,e,n){return new nr(t,e,n)}function rr(t,e){let n=e.__key,o=t.offset,r="element";if(Xo(e)){r="text";const t=e.getTextContentSize();o>t&&(o=t);}else if(!Pi(e)){const t=e.getNextSibling();if(Xo(t))n=t.__key,o=0,r="text";else {const t=e.getParent();t&&(n=t.__key,o=e.getIndexWithinParent()+1);}}t.set(n,o,r);}function ir(t,e){if(Pi(e)){const n=e.getLastDescendant();Pi(n)||Xo(n)?rr(t,n):rr(t,e);}else rr(t,e);}class sr{_nodes;_cachedNodes;dirty;constructor(t){this._cachedNodes=null,this._nodes=t,this.dirty=false;}getCachedNodes(){return this._cachedNodes}setCachedNodes(t){this._cachedNodes=t;}is(t){if(!ur(t))return  false;const e=this._nodes,n=t._nodes;return e.size===n.size&&Array.from(e).every(t=>n.has(t))}isCollapsed(){return  false}isBackward(){return  false}getStartEndPoints(){return null}add(t){this.dirty=true,this._nodes.add(t),this._cachedNodes=null;}delete(t){this.dirty=true,this._nodes.delete(t),this._cachedNodes=null;}clear(){this.dirty=true,this._nodes.clear(),this._cachedNodes=null;}has(t){return this._nodes.has(t)}clone(){return new sr(new Set(this._nodes))}extract(){return this.getNodes()}insertRawText(t){}insertText(){}insertNodes(t){const e=this.getNodes().filter(t=>null===Xc(t)),n=e.length;if(0===n)return;const o=e[n-1];let r;if(Xo(o))r=o.select();else {const t=o.getIndexWithinParent()+1;r=o.getParentOrThrow().select(t,t);}r.insertNodes(t);for(let t=0;t<n;t++)e[t].remove();}getNodes(){const t=this._cachedNodes;if(null!==t)return t;const e=this._nodes,n=[];for(const t of e){const e=Vs(t);null!==e&&n.push(e);}return ai()||(this._cachedNodes=n),n}getTextContent(){const t=this.getNodes();let e="";for(let n=0;n<t.length;n++)e+=t[n].getTextContent();return e}deleteNodes(){const t=this.getNodes().filter(t=>null===Xc(t));if((Lr()||Kr())===this&&t[0]){const e=Da(t[0],"next");Za(Ha(e,e));}for(const e of t)e.remove();lr();}}function lr(){const t=tl();if(t.isEmpty()){const e=ts();t.append(e),e.select();}}function cr(t){return t instanceof ar}class ar{format;style;anchor;focus;_cachedNodes;_cachedIsBackward;dirty;constructor(t,e,n,o){this.anchor=t,this.focus=e,t._selection=this,e._selection=this,this._cachedNodes=null,this._cachedIsBackward=null,this.format=n,this.style=o,this.dirty=false;}getCachedNodes(){return this._cachedNodes}setCachedNodes(t){this._cachedNodes=t;}is(t){return !!cr(t)&&(this.anchor.is(t.anchor)&&this.focus.is(t.focus)&&this.format===t.format&&this.style===t.style)}isCollapsed(){return this.anchor.is(this.focus)}getNodes(){const t=this._cachedNodes;if(null!==t)return t;const e=function(t){const e=[],[n,o]=t.getTextSlices();n&&e.push(n.caret.origin);const r=new Set,i=new Set;for(const n of t)if(Ea(n)){const{origin:t}=n;0===e.length?r.add(t):(i.add(t),e.push(t));}else {const{origin:t}=n;Pi(t)&&i.has(t)||e.push(t);}o&&e.push(o.caret.origin);if(wa(t.focus)&&Pi(t.focus.origin)&&null===t.focus.getNodeAtCaret())for(let n=La(t.focus.origin,"previous");Ea(n)&&r.has(n.origin)&&!n.origin.isEmpty()&&n.origin.is(e[e.length-1]);n=za(n))r.delete(n.origin),e.pop();for(;e.length>1;){const t=e[e.length-1];if(!Pi(t)||i.has(t)||t.isEmpty()||r.has(t))break;e.pop();}if(0===e.length&&t.isCollapsed()){const n=su(t.anchor),o=su(t.anchor.getFlipped()),r=t=>ba(t)?t.origin:t.getNodeAtCaret(),i=r(n)||r(o)||(t.anchor.getNodeAtCaret()?n.origin:o.origin);e.push(i);}return e}(au(eu(this),"next"));return ai()||(this._cachedNodes=e),e}setTextNodeRange(t,e,n,o){return this.anchor.set(t.__key,e,"text"),this.focus.set(n.__key,o,"text"),this}getTextContent(){const t=this.getNodes();if(0===t.length)return "";const e=t[0],n=t[t.length-1],o=this.anchor,r=this.focus,i=o.isBefore(r),[s,l]=_r(this);let c="",a=true;for(let u=0;u<t.length;u++){const f=t[u];if(Pi(f)&&!f.isInline()){a||(c+="\n");let t="";for(const e of na(f)){const n=oa(f,e);null!==n&&(t+=n.getTextContent());}""!==t?(c+=t,a=false):a=!f.isEmpty();}else if(a=false,Xo(f)){let t=f.getTextContent();f===e?f===n?"element"===o.type&&"element"===r.type&&r.offset!==o.offset||(t=s<l?t.slice(s,l):t.slice(l,s)):t=i?t.slice(s):t.slice(l):f===n&&(t=i?t.slice(0,l):t.slice(0,s)),c+=t;}else !Li(f)&&!qi(f)||f===n&&this.isCollapsed()||(c+=f.getTextContent());}return c}applyDOMRange(t){const e=gi(),n=e.getEditorState()._selection,o=Or(t.startContainer,t.startOffset,t.endContainer,t.endOffset,e,n);if(null===o)return;const[r,i,s]=o;this.anchor.set(r.key,r.offset,r.type,true),this.focus.set(i.key,i.offset,i.type,true),s&&(this.dirty=true),It$5(this);}clone(){const t=this.anchor,e=this.focus;return new ar(or(t.key,t.offset,t.type),or(e.key,e.offset,e.type),this.format,this.style)}toggleFormat(t){this.format=Bs(this.format,t,null),this.dirty=true;}setFormat(t){this.format=t,this.dirty=true;}setStyle(t){this.style=t,this.dirty=true;}hasFormat(t){const e=z$1[t];return 0!==(this.format&e)}insertRawText(t){this.insertNodes(Vr(t));}insertText(e){const n=this.anchor,o=this.focus,r=this.format,i=this.style;let s=n,l=o;!this.isCollapsed()&&o.isBefore(n)&&(s=o,l=n),"element"===s.type&&function(t,e,n,o){const r=t.getNode(),i=r.getChildAtIndex(t.offset),s=Go();if(s.setFormat(n),s.setStyle(o),es(i))i.splice(0,0,[s]);else if(null!==i){const t=Kl(r)?ts().append(s):s;i.insertBefore(t);}else if(Kl(r)){const t=r.getLastChild();Pi(t)&&!t.isInline()&&t.isEmpty()?t.append(s):r.append(ts().append(s));}else r.append(s);t.is(e)&&e.set(s.__key,0,"text"),t.set(s.__key,0,"text");}(s,l,r,i),"element"===l.type&&Qa(l,su(Xa(l,"next")));const c=s.offset;let a=l.offset;const u=this.getNodes(),f=u.length;let d=u[0];Xo(d)||t(26);const h=d.getTextContent().length,g=d.getParentOrThrow();let _=u[f-1];if(1===f&&"element"===l.type&&(a=h,l.set(s.key,a,"text")),this.isCollapsed()&&c===h&&(Is(d)||!d.canInsertTextAfter()||!g.canInsertTextAfter()&&null===d.getNextSibling())){const t=d.getNextSibling();let n;if(Xo(t)&&t.canInsertTextBefore()&&!Is(t)?n=t:(n=Go(),n.setFormat(r),n.setStyle(i),g.canInsertTextAfter()?d.insertAfter(n):g.insertAfter(n)),n.select(0,0),d=n,""!==e)return void this.insertText(e)}else if(this.isCollapsed()&&0===c&&(Is(d)||!d.canInsertTextBefore()||!g.canInsertTextBefore()&&null===d.getPreviousSibling())){const t=d.getPreviousSibling();let n;if(!Xo(t)||Is(t)?(n=Go(),n.setFormat(r),g.canInsertTextBefore()?d.insertBefore(n):g.insertBefore(n)):n=t,n.select(),d=n,""!==e)return void this.insertText(e)}else if(d.isSegmented()&&c!==h)if(null!==js())d=d.setMode("normal").setFormat(r).setStyle(i);else {const t=Go(d.getTextContent());t.setFormat(r),d.replace(t),d=t;}else if(!this.isCollapsed()&&""!==e){const t=_.getParent();if(!g.canInsertTextBefore()||!g.canInsertTextAfter()||Pi(t)&&(!t.canInsertTextBefore()||!t.canInsertTextAfter()))return this.insertText(""),br(this.anchor,this.focus),void this.insertText(e)}if(1===f){if(Fs(d)){const t=Go(e);return t.select(),void d.replace(t)}const t=d.getFormat(),n=d.getStyle();if(c!==a||t===r&&n===i){if(er(d)){const t=Go(e);return t.setFormat(r),t.setStyle(i),t.select(),void d.replace(t)}}else {if(""!==d.getTextContent()){const t=Go(e);if(t.setFormat(r),t.setStyle(i),t.select(),0===c)d.insertBefore(t,false);else {const[e]=d.splitText(c);e.insertAfter(t,false);}return void(t.isComposing()&&"text"===this.anchor.type&&(this.anchor.offset-=e.length,this._cachedNodes=null,this._cachedIsBackward=null))}d.setFormat(r),d.setStyle(i);}const o=a-c;d=d.spliceText(c,o,e,true),""===d.getTextContent()?d.remove():"text"===this.anchor.type&&(this.format=t,this.style=n,d.isComposing()&&(this.anchor.offset-=e.length,this._cachedNodes=null,this._cachedIsBackward=null));}else {const n=new Set([...d.getParentKeys(),..._.getParentKeys()]),o=Pi(d)?d:d.getParentOrThrow();let r=Pi(_)?_:_.getParentOrThrow(),i=_;if(!o.is(r)&&r.isInline())do{i=r,r=r.getParentOrThrow();}while(r.isInline());if("text"===l.type&&(0!==a||""===_.getTextContent())||"element"===l.type&&_.getIndexWithinParent()<a)if(Xo(_)&&!Fs(_)&&a!==_.getTextContentSize()){if(_.isSegmented()){const t=Go(_.getTextContent());_.replace(t),_=t;}zi(l.getNode())||"text"!==l.type||(Xo(_)||t(395),_=_.spliceText(0,a,"")),n.add(_.__key);}else {const t=_.getParentOrThrow();t.canBeEmpty()||1!==t.getChildrenSize()?_.remove():t.remove();}else n.add(_.__key);const s=r.getChildren(),g=new Set(u),p=o.is(r),y=o.isInline()&&null===d.getNextSibling()?o:d;for(let t=s.length-1;t>=0;t--){const e=s[t];if(e.is(d)||Pi(e)&&e.isParentOf(d))break;e.isAttached()&&(!g.has(e)||e.is(i)?p||y.insertAfter(e,false):e.remove());}if(!p){let t=r,e=null;for(;null!==t;){const o=t.getChildren(),r=o.length;(0===r||o[r-1].is(e))&&(n.delete(t.__key),e=t),t=t.getParent();}}if(Fs(d))if(c===h)d.select();else {const t=Go(e);t.select(),d.replace(t);}else d=d.spliceText(c,h-c,e,true),""===d.getTextContent()?d.remove():"text"===this.anchor.type&&(this.format=d.getFormat(),this.style=d.getStyle(),d.isComposing()&&(this.anchor.offset-=e.length,this._cachedNodes=null,this._cachedIsBackward=null));for(let t=1;t<f;t++){const e=u[t],o=e.__key;n.has(o)||e.remove();}}}removeText(){const t=Lr()===this;tu(this,iu(eu(this))),t&&Lr()!==this&&el(this);}formatText(t,e=null){hr(this,t,e);}insertNodes(e){if(0===e.length)return;this.isCollapsed()||this.removeText();const n=this.anchor.getNode();if("element"===this.anchor.type&&Pi(n)&&null!==Xc(n)){let o=n.isShadowRoot()?n.getFirstChild()??n.append(ts()).getFirstChild():n.getFirstChild();if(n.isShadowRoot()&&null!==o&&!Pi(o)){const t=ts();o.insertBefore(t),o=t;}if(null!==o){o.selectStart();const n=Lr();return cr(n)||t(369),n.insertNodes(e)}}if("element"===this.anchor.type&&Kl(n)){const t=Zr(e),o=t.getLastDescendant();return n.splice(this.anchor.offset,0,t.getChildren()),void(null!==o&&o.selectEnd())}let o=(this.isBackward()?this.focus:this.anchor).getNode(),r=Jc(o,xc);const i=e[e.length-1];if(Pi(r)&&"__language"in r){if("__language"in e[0])this.insertText(e[0].getTextContent());else {const t=Gr(this);r.splice(t,0,e),i.selectEnd();}return}if(!e.some(t=>(Pi(t)||Li(t))&&!t.isInline())){Pi(r)||t(211,o.constructor.name,o.getType());const n=Gr(this);return r.splice(n,0,e),void i.selectEnd()}if(Pi(r)&&null!==Xc(r)){const t=Gr(this),n=Yr(e);r.splice(t,0,n);const o=n[n.length-1];return void(void 0!==o?o.selectEnd():r.select(t,t))}if(null===r){const t=Zr(e),n=t.getLastDescendant();let o=Xa(this.anchor,"next");for(const e of t.getChildren())o=_u(e,o);return void(null!==n&&n.selectEnd())}if(Pi(r)&&!r.isParentRequired()&&!Kl(r.getParentOrThrow())){const t=Gr(this),n=Yr(e);r.splice(t,0,n);const o=n[n.length-1];return void(void 0!==o?o.selectEnd():r.select(t,t))}const s=Zr(e),l=s.getLastDescendant(),c=s.getChildren(),a=!Pi(r)||!r.isEmpty()?this.insertParagraph():null;a&&!r.isAttached()&&(o=this.anchor.getNode(),r=Jc(o,xc));const u=c[c.length-1];let f=c[0];var d;Pi(d=f)&&xc(d)&&!d.isEmpty()&&Pi(r)&&(!r.isEmpty()||r.canMergeWhenEmpty())&&(Pi(r)||t(211,o.constructor.name,o.getType()),r.append(...f.getChildren()),f=c[1]),f&&(null===r&&t(212,o.constructor.name,o.getType()),function(e,n){const o=n.getParentOrThrow().getLastChild();let r=n;const i=[n];for(;r!==o;)r.getNextSibling()||t(140),r=r.getNextSibling(),i.push(r);let s=e;for(const t of i)s=s.insertAfter(t);}(r,f));const h=Jc(l,xc);a&&Pi(h)&&(a.canMergeWhenEmpty()||xc(u))&&(h.append(...a.getChildren()),a.remove()),Pi(r)&&r.isEmpty()&&r.remove(),l.selectEnd();const g=Pi(r)?r.getLastChild():null;qi(g)&&h!==r&&g.remove();}insertParagraph(){const e=this.anchor.getNode();if("element"===this.anchor.type&&Kl(e)){const t=ts();return e.splice(this.anchor.offset,0,[t]),t.select(),t}const n=Gr(this),o=Jc(this.anchor.getNode(),xc);if(null!==o&&null!==Xc(o))return null;Pi(o)||t(213);const r=o.getChildAtIndex(n),i=r?[r,...r.getNextSiblings()]:[],s=o.insertNewAfter(this,false);return s?(s.append(...i),s.selectStart(),s):null}insertLineBreak(t){const e=Vi();if(this.insertNodes([e]),t){const t=e.getParentOrThrow(),n=e.getIndexWithinParent();t.select(n,n);}}extract(){const t=[...this.getNodes()],e=t.length;let n=t[0],o=t[e-1];const[r,i]=_r(this),s=this.isBackward(),[l,c]=s?[this.focus,this.anchor]:[this.anchor,this.focus],[a,u]=s?[i,r]:[r,i];if(0===e)return [];if(1===e){if(Xo(n)&&!this.isCollapsed()){const t=n.splitText(a,u),e=0===a?t[0]:t[1];return e?(l.set(e.getKey(),0,"text"),c.set(e.getKey(),e.getTextContentSize(),"text"),[e]):[]}return [n]}if(Xo(n)&&(a===n.getTextContentSize()?t.shift():0!==a&&([,n]=n.splitText(a),t[0]=n,l.set(n.getKey(),0,"text"))),Xo(o)){const e=o.getTextContent().length;0===u?t.pop():u!==e&&([o]=o.splitText(u),t[t.length-1]=o,c.set(o.getKey(),o.getTextContentSize(),"text"));}return t}modify(t,e,n){if(ti(this,t,e,n))return;const o="move"===t,r=gi(),i=Hl(Pl(r));if(!i)return;const s=r._blockCursorElement,l=r._rootElement,c=this.focus.getNode();null===l||null===s||!Pi(c)||c.isInline()||c.canBeEmpty()||$l(s,r,l);const a=bl(r,this.focus.key);let u=a;if("text"===this.focus.type&&(u=Xo(c)?Nc(c,a,r):null),this.dirty){const t=bl(r,this.anchor.key);let e=t;if("text"===this.anchor.type){const n=this.anchor.getNode();e=Xo(n)?Nc(n,t,r):null;}e&&u&&Ur(i,e,this.anchor.offset,u,this.focus.offset);}if("character"===n&&Xo(c)&&c.isUnmergeable()){if(e?0===this.focus.offset:this.focus.offset===c.getTextContentSize()){const t=Da(c,e?"previous":"next").getNodeAtCaret();if(Xo(t)){if(!o){const n=t.getTextContentSize();return e?this.focus.set(t.__key,n-1,"text"):this.focus.set(t.__key,1,"text"),void(this.dirty=true)}{const n=r.getElementByKey(t.getKey()),o=n?Nc(t,n,r):null;if(o){const t=e?o.length:0;Ur(i,o,t,o,t);}}}}}if(mr(i,t,e?"backward":"forward",n),i.rangeCount>0){const t=Ql(i,r._rootElement),n=t||i.getRangeAt(0),s=this.anchor.getNode(),l=zi(s)?s:Il(s);if(this.applyDOMRange(n),this.dirty=true,!o){xr(this,e,l);(t?"backward"!==i.direction:i.anchorNode===n.startContainer&&i.anchorOffset===n.startOffset)||yr(this);}}"lineboundary"===n&&ti(this,t,e,n,"decorators");}forwardDeletion(t,e,n){if(!n&&("element"===t.type&&Pi(e)&&t.offset===e.getChildrenSize()||"text"===t.type&&t.offset===e.getTextContentSize())){const t=e.getParent(),n=e.getNextSibling()||(null===t?null:t.getNextSibling());if(Pi(n)&&n.isShadowRoot())return  true}return  false}deleteCharacter(t){const e=this.isCollapsed();if(this.isCollapsed()){const e=this.anchor;let n=e.getNode();if(this.forwardDeletion(e,n,t))return;const o=Ua(Xa(e,t?"previous":"next"));if(o.getTextSlices().every(t=>null===t||0===t.distance)){let t={type:"initial"};for(const e of o.iterNodeCarets("shadowRoot"))if(Ea(e))if(e.origin.isInline());else {if(e.origin.isShadowRoot()){if("merge-block"===t.type)break;if(Pi(o.anchor.origin)&&o.anchor.origin.isEmpty()){const t=su(e);tu(this,Ha(t,t)),o.anchor.origin.remove();}return}"merge-next-block"!==t.type&&"merge-block"!==t.type||(t={block:t.block,caret:e,type:"merge-block"});}else {if("merge-block"===t.type)break;if(wa(e)){if(Pi(e.origin)){if(e.origin.isInline()){if(!e.origin.isParentOf(o.anchor.origin))break}else t={block:e.origin,type:"merge-next-block"};continue}if(Li(e.origin)){if(e.origin.isIsolated());else if(na(e.origin).length>0){if(Pi(o.anchor.origin)&&o.anchor.origin.isEmpty()){o.anchor.origin.remove();const t=Pr();t.add(e.origin.getKey()),el(t);}}else if("merge-next-block"===t.type&&(e.origin.isKeyboardSelectable()||!e.origin.isInline())&&Pi(o.anchor.origin)&&o.anchor.origin.isEmpty()){o.anchor.origin.remove();const t=Pr();t.add(e.origin.getKey()),el(t);}else e.origin.remove();return}break}}if("merge-block"===t.type){const{caret:e,block:n}=t;if(na(n).length>0)return;return e.origin.isEmpty()&&!n.isEmpty()&&e.origin.getParent()===n.getParent()?void e.origin.remove(true):(tu(this,Ha(!e.origin.isEmpty()&&n.isEmpty()?nu(Da(n,e.direction)):o.anchor,e)),this.removeText())}for(let t=e.getNode();null!==t;){if(null!==Xc(t))return;if(Pi(t)&&t.isShadowRoot())break;t=t.getParent();}}const r=this.focus;if(Cr(this,t,"character"),this.isCollapsed()){if(t&&0===e.offset&&pr(this,e.getNode()))return}else {const o="text"===r.type?r.getNode():null;if(n="text"===e.type?e.getNode():null,null!==o&&o.isSegmented()){const e=r.offset,i=o.getTextContentSize();if(o.is(n)||t&&e!==i||!t&&0!==e)return void vr(o,t,e)}else if(null!==n&&n.isSegmented()){const r=e.offset,i=n.getTextContentSize();if(n.is(o)||t&&0!==r||!t&&r!==i)return void vr(n,t,r)}!function(t,e){const n=t.anchor,o=t.focus,r=n.getNode(),i=o.getNode();if(r===i&&"text"===n.type&&"text"===o.type){const t=n.offset,i=o.offset,s=t<i,l=s?t:i,c=s?i:t,a=c-1;if(l!==a){(function(t){return !(rl(t)||Sr(t))})(r.getTextContent().slice(l,c))&&(e?o.set(o.key,a,o.type):n.set(n.key,a,n.type));}}}(this,t);}}if(this.removeText(),t&&!e&&this.isCollapsed()&&"element"===this.anchor.type&&0===this.anchor.offset){const t=this.anchor.getNode();t.isEmpty()&&zi(t.getParent())&&null===t.getPreviousSibling()&&pr(this,t),lr();}}deleteLine(t){const e=Nr(this.anchor);if(null!==e&&Li(Qc(e)))return this.isCollapsed()||this.focus.set(this.anchor.key,this.anchor.offset,this.anchor.type),void this.deleteCharacter(t);if(this.isCollapsed()&&Cr(this,t,"lineboundary"),this.isCollapsed())this.deleteCharacter(t);else {Jc(this.anchor.getNode(),xc)!==Jc(this.focus.getNode(),xc)?(this.focus.set(this.anchor.key,this.anchor.offset,this.anchor.type),this.deleteCharacter(t)):this.removeText();}}deleteWord(t){if(this.isCollapsed()){const e=this.anchor,n=e.getNode();if(this.forwardDeletion(e,n,t))return;Cr(this,t,"word");}this.isCollapsed()?this.deleteCharacter(t):this.removeText();}isBackward(){const t=this._cachedIsBackward;if(null!==t)return t;const e=this.focus.isBefore(this.anchor);return ai()||(this._cachedIsBackward=e),e}getStartEndPoints(){return [this.anchor,this.focus]}}function ur(t){return t instanceof sr}function fr(t,e){if(ur(t)){for(const n of t.getNodes())Ro(n)&&n.setFormat(e(n.getFormat()));return}if(t.isCollapsed())return t.setFormat(e(t.format)),void Js(null);const n=[];for(const o of t.getNodes())Xo(o)?n.push(o):Pi(o)?o.setTextFormat(e(o.getTextFormat())):Ro(o)&&o.setFormat(e(o.getFormat()));const o=n.length;if(0===o)return t.setFormat(e(t.format)),void Js(null);const r=t.anchor,i=t.focus,s=t.isBackward(),l=s?i:r,c=s?r:i;let a=0,u=n[0],f="element"===l.type?0:l.offset;if("text"===l.type&&f===u.getTextContentSize()&&(a=1,u=n[1],f=0),null==u)return;const d=o-1;let h=n[d];const g="text"===c.type?c.offset:h.getTextContentSize();if(u.is(h)){if(f===g)return;const n=e(u.getFormat());if(Is(u)||0===f&&g===u.getTextContentSize())u.setFormat(n);else {const t=u.splitText(f,g),e=0===f?t[0]:t[1];e.setFormat(n),"text"===l.type&&l.set(e.__key,0,"text"),"text"===c.type&&c.set(e.__key,g-f,"text");}return void(t.format=n)}0===f||Is(u)||([,u]=u.splitText(f),f=0);const _=e(u.getFormat());u.setFormat(_);const p=e(h.getFormat());g>0&&(g===h.getTextContentSize()||Is(h)||([h]=h.splitText(g)),h.setFormat(p));for(let t=a+1;t<d;t++){const o=n[t];o.setFormat(e(o.getFormat()));}"text"===l.type&&l.set(u.__key,f,"text"),"text"===c.type&&c.set(h.__key,g,"text"),t.format=_|p;}function dr(t,e){const n=[];for(const[t,o]of Object.entries(e))"boolean"==typeof o&&n.push([t,o]);0!==n.length&&fr(t,t=>{for(const[e,o]of n)t=Bs(t,e,o?z$1[e]:0);return t});}function hr(t,e,n=null){const o=null===n&&cr(t)?Bs(t.format,e,null):n;fr(t,t=>Bs(t,e,o));}function gr(t){const e=t.offset;if("text"===t.type)return e;const n=t.getNode();return e===n.getChildrenSize()?n.getTextContent().length:0}function _r(t){const e=t.getStartEndPoints();if(null===e)return [0,0];const[n,o]=e;return "element"===n.type&&"element"===o.type&&n.key===o.key&&n.offset===o.offset?[0,0]:[gr(n),gr(o)]}function pr(t,e){for(let n=e;n;n=n.getParent()){if(Pi(n)){if(n.collapseAtStart(t))return  true;if(Kl(n))break}if(n.getPreviousSibling())break}return  false}function yr(t){const e=t.focus,n=t.anchor,o=n.key,r=n.offset,i=n.type;n.set(e.key,e.offset,e.type,true),e.set(o,r,i,true);}function mr(t,e,n,o){t.modify(e,n,o);}function xr(t,e,n){const o=t.getNodes(),r=o.filter(t=>Al(t,n));if(0===r.length||r.length===o.length)return  false;const i=e?r[0]:r[r.length-1],s=Pi(i)?i:i.getParentOrThrow();return e?s.selectStart():s.selectEnd(),true}function Cr(t,e,n){if(ti(t,"extend",e,n))return;const o=gi(),r=Hl(Pl(o));if(!r||"function"!=typeof r.modify)return;const i=o._blockCursorElement,s=o._rootElement,l=t.anchor,c=t.focus.getNode();null===s||null===i||!Pi(c)||c.isInline()||c.canBeEmpty()||$l(i,o,s);const a=t=>{const e=t.getNode(),n=o.getElementByKey(t.key);return null!==n&&"text"===t.type&&Xo(e)?Nc(e,n,o):n},u=l.getNode(),f=a(l);if(null===f)return;const d=l.offset,h=t.isCollapsed(),g=t.focus,_=h?f:a(g);if(null===_)return;const p=g.offset;if(Ur(r,_,p,_,p),mr(r,"move",e?"backward":"forward",n),0===r.rangeCount)return;const y=Ql(r,s)||r.getRangeAt(0),m=y.startContainer,x=y.startOffset;if(h&&"character"===n&&"text"===l.type&&Xo(u)&&u.isUnmergeable()){if(d===(e?0:u.getTextContentSize())){const n=Da(u,e?"previous":"next").getNodeAtCaret();if(Xo(n)){const o=e?n.getTextContentSize()-1:1;return t.focus.set(n.__key,o,"text"),void(t.dirty=true)}}}if(h&&"character"===n&&"text"===l.type){const n=e?0:u.getTextContentSize(),o=m===f?x:d!==n?n:-1;if(o>=0)return void(o!==d&&(t.focus.set(l.key,o,"text"),t.dirty=true))}const[C,S,v,T]=e?[m,x,f,d]:[f,d,m,x],k=zi(u)?u:Il(u);t.applyDOMRange({collapsed:false,endContainer:v,endOffset:T,startContainer:C,startOffset:S}),t.dirty=true,!xr(t,e,k)&&e&&yr(t),"lineboundary"===n&&ti(t,"extend",e,n,"decorators");}const Sr=(()=>{try{const t=new RegExp("\\p{Emoji}","u"),e=t.test.bind(t);if(e("\u2764\ufe0f")&&e("#\ufe0f\u20e3")&&e("\u{1f44d}"))return e}catch(t){}return ()=>false})();function vr(t,e,n){const o=t,r=o.getTextContent().split(/(?=\s)/g),i=r.length;let s=0,l=0;for(let t=0;t<i;t++){const o=t===i-1;if(l=s,s+=r[t].length,e&&s===n||s>n||o){r.splice(t,1),o&&(l=void 0);break}}const c=r.join("").trim();""===c?o.remove():(o.setTextContent(c),o.select(l,l));}function Tr(e,n,o,r){let i,s=n,l=false;if(dc(e)){let c=false;const a=e.childNodes,u=a.length,f=r._blockCursorElement;s===u&&u>0&&(c=true,s=u-1),void 0!==Gs(e,r)||zc(e,r)||(l=true);let d=a[s],h=false;if(d===f)d=a[s+1],h=true;else if(null!==f){const t=f.parentNode;if(e===t){n>Array.prototype.indexOf.call(t.children,f)&&s--;}}if(i=ol(d),Xo(i))s=Fa(i,c?"next":"previous");else {let a=ol(e);if(null===a)return null;if(Pi(a)){const l=r.getElementByKey(a.getKey());null===l&&t(214);const u=vc(a,l,r);[a,s]=u.resolveChildIndex(a,l,e,n),Pi(a)||t(215),c&&s>=a.getChildrenSize()&&(s=Math.max(0,a.getChildrenSize()-1));let f=a.getChildAtIndex(s);if(Pi(f)&&function(t,e,n){const o=t.getParent();return null===n||null===o||!o.canBeEmpty()||o!==n.getNode()}(f,0,o)){const t=c?f.getLastDescendant():f.getFirstDescendant();null===t?a=f:(f=t,a=Pi(f)?f:f.getParentOrThrow()),s=0;}Xo(f)?(i=f,a=null,s=Fa(f,c?"next":"previous")):f!==a&&c&&!h&&(Pi(a)||t(216),s=Math.min(a.getChildrenSize(),s+1));}else {const t=Qc(a),o=null!==t?t:a,i=o.getIndexWithinParent(),l=r.getElementByKey(a.getKey());let c="after";if(null!==l&&ol(e)===a){const t=vc(a,l,r);t.element!==l?c=t.resolveLeafPosition(l,e,n):0===n&&Li(a)&&(c="before");}s="before"===c?i:i+1,a=o.getParentOrThrow();}if(Pi(a))return [or(a.__key,s,"element"),l]}}else i=ol(e);return Xo(i)?[or(i.__key,Fa(i,s,"clamp"),"text"),l]:null}function kr(t,e,n){const o=t.offset,r=t.getNode();if(0===o){const o=r.getPreviousSibling(),i=r.getParent();if(e){if((n||!e)&&null===o&&Pi(i)&&i.isInline()){const e=i.getPreviousSibling();Xo(e)&&t.set(e.__key,e.getTextContent().length,"text");}}else Pi(o)&&!n&&o.isInline()?t.set(o.__key,o.getChildrenSize(),"element"):Xo(o)&&!r.isUnmergeable()&&t.set(o.__key,o.getTextContent().length,"text");}else if(o===r.getTextContent().length){const o=r.getNextSibling(),i=r.getParent();if(e&&Pi(o)&&o.isInline())t.set(o.__key,0,"element");else if((n||e)&&null===o&&Pi(i)&&i.isInline()&&!i.canInsertTextAfter()&&i.getTextContentSize()>1){const e=i.getNextSibling();Xo(e)&&t.set(e.__key,0,"text");}}}function br(t,e,n){if("text"===t.type&&"text"===e.type){const n=t.isBefore(e),o=t.is(e);kr(t,n,o),kr(e,!n,o),o&&e.set(t.key,t.offset,t.type);}}function Nr(t){const e=Vs(t.key);return null===e?null:ta(e)}function wr(t,e,n){const o=Nr(t),r=Nr(e);if(o===r||null!==o&&null!==r&&o.is(r))return  false;const i=n(o,r);if(null!==o)return Pi(o)?e.set(o.getKey(),i?o.getChildrenSize():0,"element"):e.set(o.getKey(),i?o.getTextContentSize():0,"text"),true;const s=Qc(r);if(null===s)return  false;const l=s.getParent();if(null===l)return  false;const c=s.getIndexWithinParent();return e.set(l.getKey(),i?c+1:c,"element"),true}function Er(t){const e=wr(t.anchor,t.focus,(e,n)=>function(t,e,n,o){if(null!==n&&null!==o){const t=Qc(n),e=Qc(o);if(null!==t&&t.is(e)){for(const e of ea(t).values()){if(e===n.getKey())return  true;if(e===o.getKey())return  false}return  true}return null===t||null===e||t.isBefore(e)}if(null!==n){const t=Qc(n),o=Vs(e.key);return null===t||null===o||!(!t.is(o)&&!t.isParentOf(o))||t.isBefore(o)}const r=Qc(o),i=Vs(t.key);return null!==r&&null!==i&&!r.is(i)&&!r.isParentOf(i)&&i.isBefore(r)}(t.anchor,t.focus,e,n));return e&&(t.dirty=true),e}function Or(t,e,n,o,r,i){if(null===t||null===n||!Os(r,t,n))return null;const s=Tr(t,e,cr(i)?i.anchor:null,r);if(null===s)return null;const l=Tr(n,o,cr(i)?i.focus:null,r);if(null===l)return null;const[c,a]=s,[u,f]=l;if("element"===c.type&&"element"===u.type){const e=ol(t),o=ol(n);if(Li(e)&&Li(o))return null}const d=r._slotsUsed&&wr(c,u,()=>0!==(t.compareDocumentPosition(n)&Node.DOCUMENT_POSITION_FOLLOWING));return br(c,u),[c,u,a||f||d]}function Mr(t){return Pi(t)&&!t.isInline()}function Ar(t,e,n,o,r,i){const s=di(),l=new ar(or(t,e,r),or(n,o,i),0,"");return l.dirty=true,s._selection=l,l}function Dr(){const t=or("root",0,"element"),e=or("root",0,"element");return new ar(t,e,0,"")}function Pr(){return new sr(new Set)}function Fr(t,e){return Ir(null,t,e,null)}function Ir(t,e,n,o){const r=n._window;if(null===r)return null;const i=o||r.event,s=i?i.type:void 0,l="selectionchange"===s,c=!it$4&&(l||"beforeinput"===s||"compositionstart"===s||"compositionend"===s||"click"===s&&i&&3===i.detail||"drop"===s||void 0===s);let a,u,f,d;if(cr(t)&&!c)return t.clone();{if(null===e)return null;const o=tc(e,n._rootElement);if(a=o.anchorNode,u=o.focusNode,f=o.anchorOffset,d=o.focusOffset,(l||void 0===s)&&cr(t)&&!Os(n,a,u))return t.clone()}const h=Or(a,f,u,d,n,t);if(null===h)return null;const[g,_,p]=h;let y=0,m="";if(cr(t)){const e=t.anchor;if(g.key===e.key)y=t.format,m=t.style;else {const t=g.getNode();Xo(t)?(y=t.getFormat(),m=t.getStyle()):Pi(t)&&(y=t.getTextFormat(),m=t.getTextStyle());}}const x=new ar(g,_,y,m);return p&&(x.dirty=true),x}function Lr(){return di()._selection}function Kr(){return gi()._editorState._selection}function zr(t,e,n,o=1){const r=t.anchor,i=t.focus,s=r.getNode(),l=i.getNode();if(!e.is(s)&&!e.is(l))return;const c=e.__key;if(t.isCollapsed()){const e=r.offset;if(n<=e&&o>0||n<e&&o<0){const n=Math.max(0,e+o);r.set(c,n,"element"),i.set(c,n,"element"),Br(t);}}else {const s=t.isBackward(),l=s?i:r,a=l.getNode(),u=s?r:i,f=u.getNode();if(e.is(a)){const t=l.offset;(n<=t&&o>0||n<t&&o<0)&&l.set(c,Math.max(0,t+o),"element");}if(e.is(f)){const t=u.offset;(n<=t&&o>0||n<t&&o<0)&&u.set(c,Math.max(0,t+o),"element");}}Br(t);}function Br(t){const e=t.anchor,n=e.offset,o=t.focus,r=o.offset,i=e.getNode(),s=o.getNode();if(t.isCollapsed()){if(!Pi(i))return;const t=i.getChildrenSize(),r=n>=t,s=r?i.getChildAtIndex(t-1):i.getChildAtIndex(n);if(Xo(s)){let t=0;r&&(t=s.getTextContentSize()),e.set(s.__key,t,"text"),o.set(s.__key,t,"text");}return}if(Pi(i)){const t=i.getChildrenSize(),o=n>=t,r=o?i.getChildAtIndex(t-1):i.getChildAtIndex(n);if(Xo(r)){let t=0;o&&(t=r.getTextContentSize()),e.set(r.__key,t,"text");}}if(Pi(s)){const t=s.getChildrenSize(),e=r>=t,n=e?s.getChildAtIndex(t-1):s.getChildAtIndex(r);if(Xo(n)){let t=0;e&&(t=n.getTextContentSize()),o.set(n.__key,t,"text");}}}function Rr(t,e,n,o,r){let i=null,s=0,l=null;null!==o?(i=o.__key,Xo(o)?(s=o.getTextContentSize(),l="text"):Pi(o)&&(s=o.getChildrenSize(),l="element")):null!==r&&(i=r.__key,Xo(r)?l="text":Pi(r)&&(l="element")),null!==i&&null!==l?t.set(i,s,l):(s=e.getIndexWithinParent(),-1===s&&(s=n.getChildrenSize()),t.set(n.__key,s,"element"));}function Wr(t,e,n,o,r){"text"===t.type?t.set(n,t.offset+(e?0:r),"text"):t.offset>o.getIndexWithinParent()&&t.set(t.key,t.offset-1,"element");}function Ur(t,e,n,o,r){try{t.setBaseAndExtent(e,n,o,r);}catch(t){}}function $r(t,e,n){const o=bl(t,e.getKey());if(Pi(e)){const r=vc(e,o,t);return [r.element,n+r.getFirstChildOffset()]}return [o,n]}function Hr(t,e,n,o,r,s){const l=s.getRootNode(),c=Ks(l)||jl(l)?sc(l):null;if(r.has(vo)&&c!==s||null!==c&&ws(c,c))return;const a=tc(o,s);let u;if(!cr(e))return void(null!==t&&Os(n,a.anchorNode,a.focusNode)&&o.removeAllRanges());const f=e.anchor,d=e.focus,h=f.getNode(),g=d.getNode(),[_,p]=$r(n,h,f.offset),[y,m]=$r(n,g,d.offset),x=e.format,C=e.style,S=e.isCollapsed();let v=_,T=y,k=false;if("text"===f.type?(v=Xo(h)?Nc(h,_,n):null,k=h.getFormat()!==x||h.getStyle()!==C):cr(t)&&"text"===t.anchor.type&&(k=true),"text"===d.type&&(T=Xo(g)?Nc(g,y,n):null),null!==v&&null!==T){if(S&&(null===t||k||cr(t)&&(t.format!==x||t.style!==C))&&function(t,e,n,o,r,i){t._inputState.collapsedSelectionFormat={format:e,key:r,offset:o,style:n,timeStamp:i};}(n,x,C,p,f.key,performance.now()),("Range"!==o.type||!S)&&a.anchorOffset===p&&a.focusOffset===m&&a.anchorNode===v&&a.focusNode===T){if(null===c||!s.contains(c)){const t=null!==c?As(c):null;null!==t&&t!==n||r.has(No)||s.focus({preventScroll:true});}if("element"!==f.type)return}if(Ur(o,v,p,T,m),i&&e.isCollapsed()&&null!==s&&!r.has(No)){const t=ic(s);if(null===t||!s.contains(t)){const t=sc(s.ownerDocument),e=null!==t?As(t):null;null!==e&&e!==n||s.focus({preventScroll:true});}}if(!r.has(ko)&&e.isCollapsed()&&null!==s&&s===ic(s)){const t=cr(e)&&"element"===e.anchor.type?v.childNodes[p]||null:(void 0===u&&(u=Zl(o,s)),u);if(null!==t){let e;if(Ls(t)){const n=t.ownerDocument.createRange();n.selectNode(t),e=n.getBoundingClientRect();}else e=t.getBoundingClientRect();!function(t,e,n){const o=wl(n),r=Dl(o);if(null===o||null===r)return;const i=n.getBoundingClientRect();if(e.bottom<i.top)return;let{top:s,bottom:l}=e,c=0,a=0,u=n;for(;null!==u;){const e=u===o.body;if(e){const e=r.visualViewport;if(e){const t=e.offsetTop;c=t,a=t+e.height;}else c=0,a=Pl(t).innerHeight;const n=r.getComputedStyle(o.documentElement),i=parseFloat(n.scrollPaddingTop),s=parseFloat(n.scrollPaddingBottom);isFinite(i)&&(c+=i),isFinite(s)&&(a-=s);}else {const t=u===n?i:u.getBoundingClientRect();c=t.top,a=t.bottom;}let f=0;if(s<c?f=-(c-s):l>a&&(f=l-a),0!==f)if(e)r.scrollBy(0,f);else {const t=u.scrollTop;u.scrollTop+=f;const e=u.scrollTop-t;s-=e,l-=e;}if(e)break;u=Nl(u);}}(n,e,s);}}!function(t){t._inputState.isSelectionChangeFromDOMUpdate=true;}(n);}}function Jr(t){let e=Lr()||Kr();null===e&&(e=tl().selectEnd()),e.insertNodes(t);}function jr(t,e){for(const n of t.split(/(\r?\n|\t)/))"\n"===n||"\r\n"===n?e.linebreak():"\t"===n?e.tab():""!==n&&e.text(n);}function Vr(t){const e=[];return jr(t,{linebreak:()=>e.push(Vi()),tab:()=>e.push(tr()),text:t=>e.push(Go(t))}),e}function Yr(t){const e=[];for(const n of t)qi(n)||(!Pi(n)&&!Li(n)||n.isInline()?e.push(n):Pi(n)&&e.push(...Yr(n.getChildren())));return e}function Gr(e){let n=e;e.isCollapsed()||n.removeText();const o=Lr();cr(o)&&(n=o),cr(n)||t(161);const r=n.anchor;let i=r.getNode(),s=r.offset;for(;!xc(i)&&null===Xc(i);){const t=i;if([i,s]=Xr(i,s),t.is(i))break}return s}function Xr(t,e){const n=t.getParent();if(!n){const t=ts();return tl().append(t),t.select(),[tl(),0]}if(Xo(t)){const o=t.splitText(e);if(0===o.length)return [n,t.getIndexWithinParent()];const r=0===e?0:1;return [n,o[0].getIndexWithinParent()+r]}if(!Pi(t)||0===e)return [n,t.getIndexWithinParent()];const o=t.getChildAtIndex(e);if(o){const n=new ar(or(t.__key,e,"element"),or(t.__key,e,"element"),0,""),r=t.insertNewAfter(n);r&&r.append(o,...o.getNextSiblings());}return [n,t.getIndexWithinParent()+1]}function Qr(t){return qi(t)||Fl(t)||Xo(t)||t.isParentRequired()}function Zr(t){const e=ts();let n=null;for(let o=0;o<t.length;o++){const r=t[o];if(Qr(r)){if(null===n){n=r.createParentElementNode(),e.append(n);const i=t[o+1];if(qi(r)&&(void 0===i||!Qr(i)))continue}n.append(r);}else e.append(r),n=null;}return e}function ti(t,e,n,o,r="decorators-and-blocks"){if("move"===e&&"character"===o&&!t.isCollapsed()){const[e,o]=n===t.isBackward()?[t.focus,t.anchor]:[t.anchor,t.focus];return o.set(e.key,e.offset,e.type),true}const i=Xa(t.focus,n?"previous":"next"),s="lineboundary"===o,l="move"===e;let c=i,a="decorators-and-blocks"===r;if(!lu(c)){for(const t of c){a=false;const{origin:e}=t;if(!Li(e)||e.isIsolated()||(c=t,!s||!e.isInline()))break}if(a)for(const t of Ua(i).iterNodeCarets("extend"===e?"shadowRoot":"root")){if(Ea(t))t.origin.isInline()||(c=t);else {if(Pi(t.origin))continue;Li(t.origin)&&!t.origin.isInline()&&(c=t);}break}}if(c===i)return  false;if(l&&!s&&Li(c.origin)&&c.origin.isKeyboardSelectable()){const t=Pr();return t.add(c.origin.getKey()),el(t),true}return c=su(c),l&&Qa(t.anchor,c),Qa(t.focus,c),a||!s}let ei=null,ni=null,oi=false,ri=false,ii=false;const si=new Set;let li=0;const ci={characterData:true,childList:true,subtree:true};function ai(){return oi||null!==ei&&ei._readOnly}function ui(){oi&&t(13);}function fi(){li>99&&t(14);}function di(){return null===ei&&t(195,pi()),ei}function hi(t){null!==di()&&null===ni&&(ni=t),ni!==t&&e(378);}function gi(){return null===ni&&t(337,pi()),ni}function _i(){gi()._dirtyType=2;}function pi(){let t=0;const e=new Set,n=xs.version;if("undefined"!=typeof window)for(const o of Yl(document)){const r=Ds(o);if(Ms(r))t++;else if(r){let t=String(r.constructor.version||"<0.17.1");t===n&&(t+=" (separately built, likely a bundler configuration issue)"),e.add(t);}}let o=` Detected on the page: ${t} compatible editor(s) with version ${n}`;return e.size&&(o+=` and incompatible editors with versions ${Array.from(e).join(", ")}`),o}function yi(){return ni}function mi(t,e,n){const o=e.__type,r=ks(t,o);let i=n.get(o);void 0===i&&(i=Array.from(r.transforms),n.set(o,i));const s=i.length;for(let t=0;t<s&&(i[t](e),e.isAttached());t++);}function xi(t,e){return void 0!==t&&t.__key!==e&&t.isAttached()}function Ci(t,e){if(!e)return;const n=t._updateTags;let o=e;Array.isArray(e)||(o=[e]);for(const t of o)n.add(t);}function Si(t){return vi(t,gi()._nodes)}function vi(e,n){const o=e.type,r=n.get(o);void 0===r&&t(17,o);const i=r.klass;e.type!==i.getType()&&t(18,i.name);const s=i.importJSON(e),l=e.children;if(Pi(s)&&Array.isArray(l))for(let t=0;t<l.length;t++){const e=vi(l[t],n);s.append(e);}const c=e.$slots;if(c){Yc(s)||t(379,i.name);for(const t in c){ha(s,t,vi(c[t],n));}}return s}function Ti(t,e,n){const o=ei,r=oi,i=ni;ei=e,oi=true,ni=t;try{return n()}finally{ei=o,oi=r,ni=i;}}function ki(t,e){const n=ii;ii=true;try{!function(t,e){const n=t._pendingEditorState,o=t._rootElement,r=t._headless||null===o;if(null===n)return void(!t._updating&&t._deferred.length>0&&wi(t,t._deferred));const i=t._editorState,s=i._selection,l=n._selection,c=0!==t._dirtyType,a=ei,u=oi,f=ni,d=t._updating,h=t._observer;let g=null;if(t._pendingEditorState=null,t._editorState=n,!r&&c&&null!==h){ni=t,ei=n,oi=!1,t._updating=!0;try{const e=t._dirtyType,o=t._dirtyElements,r=t._dirtyLeaves;h.disconnect(),g=De$2(i,n,t,e,o,r);}catch(e){if(e instanceof Error&&t._onError(e),ri)throw e;return hs(t,null,o,n),gt$3(t),t._dirtyType=2,ri=!0,ki(t,i),void(ri=!1)}finally{h.observe(o,ci),t._updating=d,ei=a,oi=u,ni=f;}}n._readOnly||(n._readOnly=!0);const _=t._dirtyLeaves,p=t._dirtyElements,y=t._normalizedNodes,m=t._updateTags;c&&(t._dirtyType=0,t._cloneNotNeeded.clear(),t._dirtyLeaves=new Set,t._dirtyElements=new Map,t._normalizedNodes=new Set);t._updateTags=new Set,function(t,e){const n=t._decorators;let o=t._pendingDecorators||n;const r=e._nodeMap;let i;for(i in o)r.has(i)||(o===n&&(o=Qs(t)),delete o[i]);}(t,n);const x=r?null:Hl(Pl(t));if(t._editable&&null!==x&&(c||null===l||l.dirty||!l.is(s))&&null!==o&&!m.has(bo)){ni=t,ei=n;try{if(null!==h&&h.disconnect(),c||null===l||l.dirty){const e=t._blockCursorElement;null!==e&&$l(e,t,o),Hr(s,l,t,x,m,o);}!function(t,e,n){let o=t._blockCursorElement;if(cr(n)&&n.isCollapsed()&&"element"===n.anchor.type&&e.contains(ic(e))){const r=n.anchor,i=r.getNode(),s=r.offset;let l=!1,c=null;if(s===i.getChildrenSize()){Ul(i.getChildAtIndex(s-1))&&(l=!0);}else {const e=i.getChildAtIndex(s);if(null!==e&&Ul(e)){const n=e.getPreviousSibling();(null===n||Ul(n))&&(l=!0,c=t.getElementByKey(e.__key));}}if(l){const n=vc(i,t.getElementByKey(i.__key),t).element;return null===o&&(t._blockCursorElement=o=function(t){const e=t.theme,n=Xl().createElement("div");n.contentEditable="false",n.setAttribute("data-lexical-cursor","true");let o=e.blockCursor;if(void 0!==o){if("string"==typeof o){const t=Su(o);o=e.blockCursor=t;}void 0!==o&&n.classList.add(...o);}return n}(t._config)),e.style.caretColor="transparent",void(null===c?n.appendChild(o):n.insertBefore(o,c))}}null!==o&&$l(o,t,e);}(t,o,l);}finally{null!==h&&h.observe(o,ci),ni=f,ei=a;}}null!==g&&function(t,e,n,o,r){const i=Array.from(t._listeners.mutation),s=i.length;for(let t=0;t<s;t++){const[s,l]=i[t];for(const t of l){const i=e.get(t);void 0!==i&&s(i,{dirtyLeaves:o,prevEditorState:r,updateTags:n});}}}(t,g,m,_,i);cr(l)||null===l||null!==s&&s.is(l)||t.dispatchCommand(Ie$2,void 0);const C=t._pendingDecorators;null!==C&&(t._decorators=C,t._pendingDecorators=null,bi("decorator",t,!0,C));if(function(t,e,n){const o=Zs(e),r=Zs(n);o!==r&&bi("textcontent",t,!0,r);}(t,e||i,n),bi("update",t,!0,{dirtyElements:p,dirtyLeaves:_,editorState:n,mutatedNodes:g,normalizedNodes:y,prevEditorState:e||i,tags:m}),!d){wi(t,t._deferred);}!function(t){const e=t._updates;if(0===e.length)return void(t._cascadeCount=0);if(function(t){if(si.has(t))return;si.add(t),setTimeout(()=>{si.delete(t),t._cascadeCount=0;},0);}(t),t._cascadeCount++>99)return t._updates=[],t._cascadeCount=0,void t._onWarn(new Error(`One or more update listeners are endlessly enqueueing more updates. May have encountered infinite recursion caused by update listeners that trigger additional updates without a stop condition. Editor namespace: ${t._config.namespace}`));const n=e.shift();if(n){const[e,o]=n;Oi(t,e,o);}}(t);}(t,e);}finally{ii=n;}}function bi(t,e,n,...o){const r=e._updating;e._updating=n;try{const n=e._listeners[t],r=Array.from(n);for(const[t,e]of r){e&&e();const r=t(...o);n.has(t)?n.set(t,r):r&&r();}}finally{e._updating=r;}}function Ni(t,e,n,o){const r=il(t);let i;if(!ii)for(let t=0;t<r.length;t++)r[t]._updating||(r[t]._cascadeCount=0);for(let t=4;t>=0;t--)for(let s=0;s<r.length;s++){const l=r[s];if(s>0&&l._updating){i=l;break}const c=l._commands.get(e);if(void 0!==c){const e=c[t];if(e.size>0){let t=false;if(Mi(l,()=>{for(const r of e)if(r(n,o))return void(t=true)}),t)return t}}}return i&&i.update(()=>{Ni(i,e,n,o);}),false}function wi(t,e){if(t._deferred=[],0!==e.length){const n=t._updating;t._updating=true;try{for(let t=0;t<e.length;t++)e[t]();}finally{t._updating=n;}}}function Ei(e,n){const o=e._updates;let r=n||false;for(;0!==o.length;){const n=o.shift();if(n){const[o,i]=n,s=e._pendingEditorState;let l;void 0!==i&&(l=i.onUpdate,i.skipTransforms&&(r=true),i.discrete&&(null===s&&t(191),s._flushSync=true),l&&e._deferred.push(l),Ci(e,i.tag)),null==s?Oi(e,o,i):o();}}return r}function Oi(e,n,o){const r=e._updateTags;let i,s=false,l=false;void 0!==o&&(i=o.onUpdate,Ci(e,o.tag),s=o.skipTransforms||false,l=o.discrete||false),i&&e._deferred.push(i);const c=e._editorState;let a=e._pendingEditorState,u=false;(null===a||a._readOnly)&&(a=e._pendingEditorState=Bi(a||c),u=true),a._flushSync=l;const f=ei,d=oi,h=ni,g=e._updating;ei=a,oi=false,e._updating=true,ni=e;const _=e._headless||null===e.getRootElement();Ss(null);try{u&&(_?null!==c._selection&&(a._selection=c._selection.clone()):a._selection=function(t,e){const n=t.getEditorState()._selection,o=Hl(Pl(t));return cr(n)||null==n?Ir(n,o,t,e):n.clone()}(e,o&&o.event||null));const r=e._compositionKey;n(),s=Ei(e,s),function(t,e){const n=e.getEditorState()._selection,o=t._selection;if(cr(o)){const t=o.anchor,e=o.focus;let r;if("text"===t.type&&(r=t.getNode(),r.selectionTransform(n,o)),"text"===e.type){const t=e.getNode();r!==t&&t.selectionTransform(n,o);}}}(a,e),0!==e._dirtyType&&(s?function(t,e){const n=e._dirtyLeaves,o=t._nodeMap;for(const t of n){const e=o.get(t);Xo(e)&&e.isAttached()&&e.isSimpleText()&&!e.isUnmergeable()&&Ft$5(e);}}(a,e):function(t,e){const n=e._dirtyLeaves,o=e._dirtyElements,r=t._nodeMap,i=js(),s=new Map;let l=n,c=l.size,a=o,u=a.size;for(;c>0||u>0;){if(c>0){e._dirtyLeaves=new Set;for(const t of l){const o=r.get(t);Xo(o)&&o.isAttached()&&o.isSimpleText()&&!o.isUnmergeable()&&Ft$5(o),void 0!==o&&xi(o,i)&&mi(e,o,s),n.add(t);}if(l=e._dirtyLeaves,c=l.size,c>0){li++;continue}}e._dirtyLeaves=new Set,e._dirtyElements=new Map,a.delete("root")&&a.set("root",!0);for(const t of a){const n=t[0],l=t[1];if(o.set(n,l),!l)continue;const c=r.get(n);void 0!==c&&xi(c,i)&&mi(e,c,s);}l=e._dirtyLeaves,c=l.size,a=e._dirtyElements,u=a.size,li++;}e._dirtyLeaves=n,e._dirtyElements=o;}(a,e),Ei(e),function(t,e,n,o){const r=t._nodeMap,i=e._nodeMap,s=[];for(const[t]of o){const e=i.get(t);void 0!==e&&(e.isAttached()||(Pi(e)&&rt$5(e,t,r,i,s,o),r.has(t)||o.delete(t),s.push(t)));}for(const t of n){const e=i.get(t);void 0===e||e.isAttached()||(Yc(e)&&null!==e.__slots&&rt$5(e,t,r,i,s,n),r.has(t)||n.delete(t),s.push(t));}for(const t of s)i.delete(t);const l=gi(),c=l._compositionKey;null===c||i.has(c)||(l._compositionKey=null);}(c,a,e._dirtyLeaves,e._dirtyElements));r!==e._compositionKey&&(a._flushSync=!0);const i=a._selection;if(cr(i)){e._slotsUsed&&Er(i);const n=a._nodeMap,o=i.anchor.key,r=i.focus.key;void 0!==n.get(o)&&void 0!==n.get(r)||t(19);}else ur(i)&&0===i._nodes.size&&(a._selection=null);}catch(t){return t instanceof Error&&e._onError(t),e._pendingEditorState=c,e._dirtyType=2,e._cloneNotNeeded.clear(),e._dirtyLeaves=new Set,e._dirtyElements.clear(),void ki(e)}finally{ei=f,oi=d,ni=h,e._updating=g,li=0;}const p=0!==e._dirtyType||e._deferred.length>0||function(t,e){const n=e.getEditorState()._selection,o=t._selection;if(null!==o){if(o.dirty||!o.is(n))return  true}else if(null!==n)return  true;return  false}(a,e);p?a._flushSync?(a._flushSync=false,ki(e)):u&&Ns(()=>{ki(e);}):(a._flushSync=false,u&&(r.clear(),e._deferred=[],e._pendingEditorState=null));}function Mi(t,e,n){ni===t&&void 0===n?e():Oi(t,e,n);}function Ai(t){if(Kl(t)){let e=null;for(const n of t.getChildren())e=n.isInline()?(e||n.replace(n.createParentElementNode())).append(n):null;}}class Di extends _o{__first;__last;__size;__format;__style;__indent;__dir;__textFormat;__textStyle;__slotHost;__slots;$config(){return this.config(Symbol.for("ElementNode"),{$transform:Ai,extends:_o})}constructor(t){super(t),this.__first=null,this.__last=null,this.__size=0,this.__format=0,this.__style="",this.__indent=0,this.__dir=null,this.__textFormat=0,this.__textStyle="",this.__slotHost=null,this.__slots=null;}afterCloneFrom(e){super.afterCloneFrom(e),this.__key===e.__key&&(this.__first=e.__first,this.__last=e.__last,this.__size=e.__size,this.__slotHost=e.__slotHost,null!==this.__slotHost&&null!==this.__parent&&t(384,this.__key,String(this.__slotHost),String(this.__parent)),this.__slots=e.__slots),this.__indent=e.__indent,this.__format=e.__format,this.__style=e.__style,this.__dir=e.__dir,this.__textFormat=e.__textFormat,this.__textStyle=e.__textStyle;}getFormat(){return this.getLatest().__format}getFormatType(){const t=this.getFormat();return W[t]||""}getStyle(){return this.getLatest().__style}getIndent(){return this.getLatest().__indent}getChildren(){const t=[];let e=this.getFirstChild();for(;null!==e;)t.push(e),e=e.getNextSibling();return t}getChildrenKeys(){const t=[];let e=this.getFirstChild();for(;null!==e;)t.push(e.__key),e=e.getNextSibling();return t}getChildrenSize(){return this.getLatest().__size}isEmpty(){return 0===this.getChildrenSize()&&0===na(this).length}isDirty(){const t=gi()._dirtyElements;return null!==t&&t.has(this.__key)}isLastChild(){const t=this.getLatest(),e=this.getParentOrThrow().getLastChild();return null!==e&&e.is(t)}getAllTextNodes(){const t=[];for(const e of na(this)){const n=oa(this,e);Pi(n)&&t.push(...n.getAllTextNodes());}let e=this.getFirstChild();for(;null!==e;){if(Xo(e)&&t.push(e),Pi(e)){const n=e.getAllTextNodes();t.push(...n);}e=e.getNextSibling();}return t}getFirstDescendant(){let t=this.getFirstChild();for(;Pi(t);){const e=t.getFirstChild();if(null===e)break;t=e;}return t}getLastDescendant(){let t=this.getLastChild();for(;Pi(t);){const e=t.getLastChild();if(null===e)break;t=e;}return t}getDescendantByIndex(t){const e=this.getChildren(),n=e.length;if(t>=n){const t=e[n-1];return Pi(t)&&t.getLastDescendant()||t||null}const o=e[t];return Pi(o)&&o.getFirstDescendant()||o||null}getFirstChild(){const t=this.getLatest().__first;return null===t?null:Vs(t)}getFirstChildOrThrow(){const e=this.getFirstChild();return null===e&&t(45,this.__key),e}getLastChild(){const t=this.getLatest().__last;return null===t?null:Vs(t)}getLastChildOrThrow(){const e=this.getLastChild();return null===e&&t(96,this.__key),e}getChildAtIndex(t){const e=this.getChildrenSize();let n,o;if(t<e/2){for(n=this.getFirstChild(),o=0;null!==n&&o<=t;){if(o===t)return n;n=n.getNextSibling(),o++;}return null}for(n=this.getLastChild(),o=e-1;null!==n&&o>=t;){if(o===t)return n;n=n.getPreviousSibling(),o--;}return null}getTextContent(){let t=ua(this);const e=this.getChildren(),n=e.length;for(let o=0;o<n;o++){const r=e[o];t+=r.getTextContent(),Pi(r)&&o!==n-1&&!r.isInline()&&(t+=D$1);}return t}getTextContentSize(){let t=function(t){let e=0;for(const n of na(t)){const o=oa(t,n);null!==o&&(e+=o.getTextContentSize());}return e}(this);const e=this.getChildren(),n=e.length;for(let o=0;o<n;o++){const r=e[o];t+=r.getTextContentSize(),Pi(r)&&o!==n-1&&!r.isInline()&&(t+=2);}return t}getDirection(){return this.getLatest().__dir}getTextFormat(){return this.getLatest().__textFormat}hasFormat(t){if(""!==t){const e=R$1[t];return 0!==(this.getFormat()&e)}return  false}hasTextFormat(t){const e=z$1[t];return 0!==(this.getTextFormat()&e)}getFormatFlags(t,e){return Bs(this.getLatest().__textFormat,t,e)}getTextStyle(){return this.getLatest().__textStyle}select(t,e){ui();const n=Lr();let o=t,r=e;const i=this.getChildrenSize();if(!this.canBeEmpty())if(0===t&&0===e){const t=this.getFirstChild();if(Xo(t)||Pi(t))return t.select(0,0)}else if(!(void 0!==t&&t!==i||void 0!==e&&e!==i)){const t=this.getLastChild();if(Xo(t)||Pi(t))return t.select()} void 0===o&&(o=i),void 0===r&&(r=i);const s=this.__key;return cr(n)?(n.anchor.set(s,o,"element"),n.focus.set(s,r,"element"),n.dirty=true,n):Ar(s,o,s,r,"element","element")}selectStart(){const t=this.getFirstDescendant();return t?t.selectStart():this.select()}selectEnd(){const t=this.getLastDescendant();return t?t.selectEnd():this.select()}clear(){const t=this.getWritable();return this.getChildren().forEach(t=>t.remove()),t}append(...t){return this.splice(this.getChildrenSize(),0,t)}setDirection(t){const e=this.getWritable();return e.__dir=t,e}setFormat(t){return this.getWritable().__format=""!==t&&R$1[t]||0,this}setStyle(t){return this.getWritable().__style=t||"",this}setTextFormat(t){const e=this.getWritable();return e.__textFormat=t,e}setTextStyle(t){const e=this.getWritable();return e.__textStyle=t,e}setIndent(t){return this.getWritable().__indent=t,this}splice(e,n,o){ho$1(this)&&t(324,this.__key,this.__type);const r=this.getChildrenSize(),i=this.getWritable();e+n<=r||t(226,String(e),String(n),String(r));for(const t of o);const s=i.__key,l=[],c=[],a=this.getChildAtIndex(e+n);let u=null,f=r-n+o.length;if(0!==e)if(e===r)u=this.getLastChild();else {const t=this.getChildAtIndex(e);null!==t&&(u=t.getPreviousSibling());}if(n>0){let e=null===u?this.getFirstChild():u.getNextSibling();for(let o=0;o<n;o++){null===e&&t(100);const n=e.getNextSibling(),o=e.__key;Us(e.getWritable()),c.push(o),e=n;}}let d=u;for(const e of o){null!==d&&e.is(d)&&(u=d=d.getPreviousSibling());const n=e.getWritable();n.__parent===s&&f--,Us(n);const o=e.__key;if(null===d)i.__first=o,n.__prev=null;else {const t=d.getWritable();t.__next=o,n.__prev=t.__key;}e.__key===s&&t(76),n.__parent=s,l.push(o),d=e;}if(e+n===r){if(null!==d){d.getWritable().__next=null,i.__last=d.__key;}}else if(null!==a){const t=a.getWritable();if(null!==d){const e=d.getWritable();t.__prev=d.__key,e.__next=a.__key;}else t.__prev=null;}if(i.__size=f,c.length){const t=Lr();if(cr(t)){const e=new Set(c),n=new Set(l),{anchor:o,focus:r}=t;Fi(o,e,n)&&Rr(o,o.getNode(),this,u,a),Fi(r,e,n)&&Rr(r,r.getNode(),this,u,a),0!==f||this.canBeEmpty()||Kl(this)||this.remove();}}return i}getDOMSlot(t){return new G$1(t)}exportDOM(t){const{element:e}=super.exportDOM(t);if(dc(e)){const t=this.getIndent();t>0&&(e.style.paddingInlineStart=40*t+"px",e.setAttribute("data-lexical-indent",String(t)));const n=this.getDirection();n&&(e.dir=n);}return {element:e}}exportJSON(){const t={children:[],direction:this.getDirection(),format:this.getFormatType(),indent:this.getIndent(),...super.exportJSON()},e=this.getTextFormat(),n=this.getTextStyle();return 0===e&&""===n||Kl(this)||this.getChildren().some(Xo)||(0!==e&&(t.textFormat=e),""!==n&&(t.textStyle=n)),t}updateFromJSON(t){return super.updateFromJSON(t).setFormat(t.format).setIndent(t.indent).setDirection(t.direction).setTextFormat(t.textFormat||0).setTextStyle(t.textStyle||"")}insertNewAfter(t,e){return null}canIndent(){return  true}collapseAtStart(t){return  false}excludeFromCopy(t){return  false}canReplaceWith(t){return  true}canInsertAfter(t){return  true}canBeEmpty(){return  true}canInsertTextBefore(){return  true}canInsertTextAfter(){return  true}isInline(){return  false}isShadowRoot(){return  false}canMergeWith(t){return  false}extractWithChild(t,e,n){return  false}canMergeWhenEmpty(){return  false}reconcileObservedMutation(t,e){const n=vc(this,t,e);let o=n.getFirstChild();for(let t=this.getFirstChild();t;t=t.getNextSibling()){const r=e.getElementByKey(t.getKey());null!==r&&(null==o?(n.insertChild(r),o=r):o!==r&&n.replaceChild(r,o),o=o.nextSibling);}}}function Pi(t){return t instanceof Di}function Fi(t,e,n){let o=t.getNode();for(;o;){const t=o.__key;if(e.has(t)&&!n.has(t))return  true;o=o.getParent();}return  false}class Ii extends _o{__slotHost;__slots;constructor(t){super(t),this.__slotHost=null,this.__slots=null;}afterCloneFrom(e){super.afterCloneFrom(e),this.__key===e.__key&&(this.__slotHost=e.__slotHost,null!==this.__slotHost&&null!==this.__parent&&t(383,this.__key,String(this.__slotHost),String(this.__parent)),this.__slots=e.__slots);}decorate(t,e){return null}isIsolated(){return  false}isInline(){return  true}isKeyboardSelectable(){return  true}}function Li(t){return t instanceof Ii}class Ki extends Di{__cachedText;static getType(){return "root"}static clone(){return new Ki}constructor(){super("root"),this.__cachedText=null;}getTopLevelElementOrThrow(){t(51);}getTextContent(){const t=this.__cachedText;return null===t||!ai()&&0!==gi()._dirtyType?super.getTextContent():t}remove(){t(52);}replace(e){t(53);}insertBefore(e){t(54);}insertAfter(e){t(55);}updateDOM(t,e){return  false}splice(e,n,o){for(const e of o)Pi(e)||Li(e)||t(282);return super.splice(e,n,o)}static importJSON(t){return tl().updateFromJSON(t)}collapseAtStart(){return  true}}function zi(t){return t instanceof Ki}function Bi(t){return new $i(nt$5(t._nodeMap),null,t._slotsUsed)}function Ri(){return new $i(new Map([["root",new Ki]]),null,false)}function Wi(e){const n=e.exportJSON(),o=e.constructor;if(n.type!==o.getType()&&t(130,o.name),Pi(e)){const r=n.children;Array.isArray(r)||t(59,o.name);const i=e.getChildren();for(let t=0;t<i.length;t++){const e=Wi(i[t]);r.push(e);}}const r=na(e);if(r.length>0){const i={};for(const n of r){const r=oa(e,n);null===r&&t(366,o.name,n),i[n]=Wi(r);}n.$slots=i;}return n}function Ui(t){return t instanceof $i}class $i{_nodeMap;_selection;_flushSync;_readOnly;_parsed;_slotsUsed;constructor(t,e=null,n=false){this._nodeMap=t,this._selection=e||null,this._flushSync=false,this._readOnly=false,this._parsed=false,this._slotsUsed=n;}isEmpty(){return 1===this._nodeMap.size&&null===this._selection}read(t,e){return Ti(e&&e.editor||null,this,t)}clone(t){const e=new $i(this._nodeMap,void 0===t?this._selection:t,this._slotsUsed);return e._readOnly=true,e}toJSON(){return Ti(null,this,()=>({root:Wi(tl())}))}}class Hi extends Di{static getType(){return "artificial"}createDOM(t){return Xl().createElement("div")}}class Ji extends _o{static getType(){return "linebreak"}static clone(t){return new Ji(t.__key)}constructor(t){super(t);}getTextContent(){return "\n"}createDOM(){return Xl().createElement("br")}updateDOM(){return  false}isInline(){return  true}static importDOM(){return {br:t=>Yi(t)||Gi(t)?null:{conversion:ji,priority:0}}}static importJSON(t){return Vi().updateFromJSON(t)}}function ji(t){return {node:Vi()}}function Vi(){return Bl(new Ji)}function qi(t){return t instanceof Ji}function Yi(t){const e=t.parentElement;if(null!==e&&mc(e)){const n=e.firstChild;if(n===t||n.nextSibling===t&&Xi(n)){const n=e.lastChild;if(n===t||n.previousSibling===t&&Xi(n))return  true}}return  false}function Gi(t){const e=t.parentElement;if(null!==e&&mc(e)){const n=e.firstChild;if(n===t||n.nextSibling===t&&Xi(n))return  false;const o=e.lastChild;if(o===t||o.previousSibling===t&&Xi(o))return  true}return  false}function Xi(t){return Ls(t)&&/^( |\t|\r?\n)+$/.test(t.textContent||"")}class Qi extends Di{static getType(){return "paragraph"}static clone(t){return new Qi(t.__key)}createDOM(t){const e=Xl().createElement("p"),n=ml(t.theme,"paragraph");if(void 0!==n){e.classList.add(...n);}return e}updateDOM(t,e,n){return  false}static importDOM(){return {p:t=>({conversion:Zi,priority:0})}}exportDOM(t){const{element:e}=super.exportDOM(t);if(dc(e)){this.isEmpty()&&e.append(Xl().createElement("br"));const t=this.getFormatType();t&&(e.style.textAlign=t);}return {element:e}}static importJSON(t){return ts().updateFromJSON(t)}exportJSON(){const t=super.exportJSON();if(void 0===t.textFormat||void 0===t.textStyle){const e=this.getChildren().find(Xo);e?(t.textFormat=e.getFormat(),t.textStyle=e.getStyle()):(t.textFormat=this.getTextFormat(),t.textStyle=this.getTextStyle());}return t}insertNewAfter(t,e){const n=ts();n.setTextFormat(t.format),n.setTextStyle(t.style);const o=this.getDirection();return n.setDirection(o),n.setFormat(this.getFormatType()),n.setStyle(this.getStyle()),this.insertAfter(n,e),n}collapseAtStart(){const t=this.getChildren();if(0===t.length||Xo(t[0])&&""===t[0].getTextContent().trim()){if(null!==this.getNextSibling())return this.selectNext(),this.remove(),true;if(null!==this.getPreviousSibling())return this.selectPrevious(),this.remove(),true}return  false}}function Zi(t){const e=ts();if(Fc(e,t),Dc(t,e),""===e.getFormatType()){const n=t.getAttribute("align");n&&n&&n in R$1&&e.setFormat(n);}return Pc(e,t),{node:e}}function ts(){return Bl(new Qi)}function es(t){return t instanceof Qi}function ns(t){console.warn(t);}const os=0,rs=1,ss=3,ls=4,cs=-8;function hs(t,e,n,o,r){const i=t._keyToDOMMap;i.clear(),t._editorState=Ri(),t._pendingEditorState=o,t._compositionKey=null,t._dirtyType=0,t._cloneNotNeeded.clear(),t._dirtyLeaves=new Set,t._dirtyElements.clear(),t._normalizedNodes=new Set,r&&r.preserveUpdateQueue||(t._updateTags=new Set,t._updates=[],t._cascadeCount=0),t._blockCursorElement=null,null!==t._inputState.handledSelectionCommandTimeoutId&&clearTimeout(t._inputState.handledSelectionCommandTimeoutId),t._inputState={collapsedSelectionFormat:{format:0,key:"root",offset:0,style:"",timeStamp:0},compositionEndData:"",compositionPhase:"idle",hadOrphanedCompositionEvents:false,handledSelectionCommandTimeoutId:null,isInsertLineBreak:false,isInsertTextAfterHandledSelectionCommand:false,isSelectionChangeFromDOMUpdate:false,isSelectionChangeFromMouseDown:false,lastBeforeInputInsertTextTimeStamp:0,lastKeyCode:null,lastKeyDownTimeStamp:0,postDeleteSelectionToRestore:null,unprocessedBeforeInputData:null};const s=t._observer;null!==s&&(s.disconnect(),t._observer=null),null!==e&&(e.textContent="",function(t,e){const n=`__lexicalKey_${e._key}`;delete t[n];}(e,t)),null!==n&&(n.textContent="",i.set("root",n),Ys(n,t,"root"));}function gs(t){const e=new Set,n=new Set;for(const{klass:o,ownNodeConfig:r}of Uc(t)){const t=o.transform;if(!n.has(t)){n.add(t);const r=o.transform();r&&e.add(r);}if(r){const t=r.$transform;t&&e.add(t);}}return e}const _s={$createDOM:(t,e)=>t.createDOM(e._config,e),$decorateDOM:(t,e,n,o)=>{},$exportDOM:(t,e)=>{const n=bs(e,t.getType());return n&&void 0!==n.exportDOM?n.exportDOM(e,t):t.exportDOM(e)},$extractWithChild:(t,e,n,o,r)=>Pi(t)&&t.extractWithChild(e,n,o),$getDOMSlot:(t,e,n)=>t.getDOMSlot(e),$getSlotTargetElement:(t,e,n,o)=>null,$shouldExclude:(t,e,n)=>Pi(t)&&t.excludeFromCopy("html"),$shouldInclude:(t,e,n)=>!e||t.isSelected(e),$updateDOM:(t,e,n,o)=>t.updateDOM(e,n,o._config)};function ps(e){const n=e||{},o=yi(),r=n.theme||{},i=void 0===e?o:n.parentEditor||null,s=n.disableEvents||false,l=Ri(),c=n.namespace||(null!==i?i._config.namespace:sl()),a=n.editorState,u=[Ki,Wo,Ji,Zo,Qi,Hi,...n.nodes||[]],{onError:f,onWarn:d,html:h}=n,g=void 0===n.editable||n.editable;let _;if(void 0===e&&null!==o)_=o._nodes;else {_=new Map;for(let e=0;e<u.length;e++){let o=u[e],r=null,i=null;if(o&&"object"==typeof o){const t=o;o=t.replace,r=t.with,i=t.withKlass||null;}if("function"!=typeof o||!o.prototype||!(o===_o||o.prototype instanceof _o)){let r="<unknown>";try{r=JSON.parse(Z$4);}catch(t){}t(365,String(e-u.length+(n.nodes?n.nodes.length:0)),"function"==typeof o?`${o.name}${"function"==typeof o.getType?` (type ${String(o.getType())})`:""}`:String(o),String(r));}Wc(o);const s=o.getType(),l=gs(o);_.set(s,{exportDOM:h&&h.export?h.export.get(o):void 0,klass:o,replace:r,replaceWithKlass:i,sharedNodeState:vt$5(u[e]),transforms:l});}}const p=new xs(l,i,_,{disableEvents:s,dom:{..._s,...e&&e.dom},namespace:c,theme:r},f||console.error,d||ns,function(t,e){const n=new Map,o=new Set,r=t=>{Object.keys(t).forEach(e=>{let o=n.get(e);void 0===o&&(o=[],n.set(e,o)),o.push(t[e]);});};return t.forEach(t=>{const e=t.klass.importDOM;if(null==e||o.has(e))return;o.add(e);const n=e.call(t.klass);null!==n&&r(n);}),e&&r(e),n}(_,h?h.import:void 0),g,e);return void 0!==a&&(p._pendingEditorState=a,p._dirtyType=2),function(t){t.registerCommand(ze$2,Yn$1,os),t.registerCommand(Be$3,Gn$1,os),t.registerCommand(Re$1,Xn$1,os),t.registerCommand(We$2,Qn,os),t.registerCommand(tn$3,eo$1,os);}(p),p}function ys(t,e){const n=t.get(e);t.delete(e),n&&n();}function ms(t,e,n){return t.set(e,n),ys.bind(null,t,e)}class xs{static version;_headless;_parentEditor;_rootElement;_editorState;_pendingEditorState;_compositionKey;_deferred;_keyToDOMMap;_updates;_updating;_cascadeCount;_listeners;_commands;_nodes;_decorators;_pendingDecorators;_config;_dirtyType;_cloneNotNeeded;_dirtyLeaves;_dirtyElements;_normalizedNodes;_updateTags;_observer;_key;_onError;_onWarn;_htmlConversions;_window;_editable;_blockCursorElement;_slotsUsed;_inputState;_createEditorArgs;constructor(t,e,n,o,r,i,s,l,c){this._createEditorArgs=c,this._parentEditor=e,this._rootElement=null,this._editorState=t,this._pendingEditorState=null,this._compositionKey=null,this._deferred=[],this._keyToDOMMap=new ot$4,this._updates=[],this._updating=false,this._cascadeCount=0,this._listeners={decorator:new Map,editable:new Map,mutation:new Map,root:new Map,textcontent:new Map,update:new Map},this._commands=new Map,this._config=o,this._nodes=n,this._decorators={},this._pendingDecorators=null,this._dirtyType=0,this._cloneNotNeeded=new Set,this._dirtyLeaves=new Set,this._dirtyElements=new Map,this._normalizedNodes=new Set,this._updateTags=new Set,this._observer=null,this._key=sl(),this._onError=r,this._onWarn=i,this._htmlConversions=s,this._editable=l,this._headless=null!==e&&e._headless,this._window=null,this._blockCursorElement=null,this._slotsUsed=false,this._inputState={collapsedSelectionFormat:{format:0,key:"root",offset:0,style:"",timeStamp:0},compositionEndData:"",compositionPhase:"idle",hadOrphanedCompositionEvents:false,handledSelectionCommandTimeoutId:null,isInsertLineBreak:false,isInsertTextAfterHandledSelectionCommand:false,isSelectionChangeFromDOMUpdate:false,isSelectionChangeFromMouseDown:false,lastBeforeInputInsertTextTimeStamp:0,lastKeyCode:null,lastKeyDownTimeStamp:0,postDeleteSelectionToRestore:null,unprocessedBeforeInputData:null};}isComposing(){return null!=this._compositionKey}registerUpdateListener(t){return ms(this._listeners.update,t)}registerEditableListener(t){return ms(this._listeners.editable,t)}registerDecoratorListener(t){return ms(this._listeners.decorator,t)}registerTextContentListener(t){return ms(this._listeners.textcontent,t)}registerRootListener(t){const e=this._listeners.root;return ku(ms(e,t,t(this._rootElement,null)||void 0),()=>function(t,e,n){const o=t.get(e);o&&o(),t.set(e,e(...n)||void 0);}(e,t,[null,this._rootElement]))}registerCommand(e,n,o){ void 0===o&&t(35);const r=this._commands;r.has(e)||r.set(e,[new tt$3,new tt$3,new tt$3,new tt$3,new tt$3]);const i=r.get(e);void 0===i&&t(36,String(e));const s=function(t){return 7&t}(o),l=i[s];return s!==o?l.addFront(n):l.addBack(n),()=>{l.delete(n),i.every(t=>0===t.size)&&r.delete(e);}}registerMutationListener(t,e,n){const o=this.resolveRegisteredNodeAfterReplacements(this.getRegisteredNode(t)).klass,r=this._listeners.mutation;let i=r.get(e);void 0===i&&(i=new Set,r.set(e,i)),i.add(o);const s=n&&n.skipInitialization;return void 0!==s&&s||this.initializeMutationListener(e,o),()=>{i.delete(o),0===i.size&&r.delete(e);}}getRegisteredNode(e){const n=this._nodes.get(e.getType());return void 0===n&&t(37,e.name),n}resolveRegisteredNodeAfterReplacements(t){for(;t.replaceWithKlass;)t=this.getRegisteredNode(t.replaceWithKlass);return t}initializeMutationListener(t,e){const n=this._editorState,o=Oc(n).get(e.getType());if(!o)return;const r=new Map;for(const t of o.keys())r.set(t,"created");r.size>0&&t(r,{dirtyLeaves:new Set,prevEditorState:n,updateTags:new Set(["registerMutationListener"])});}registerNodeTransformToKlass(t,e){const n=this.getRegisteredNode(t);return n.transforms.add(e),n}registerNodeTransform(t,e){const n=this.registerNodeTransformToKlass(t,e),o=[n],r=n.replaceWithKlass;if(null!=r){const t=this.registerNodeTransformToKlass(r,e);o.push(t);}return function(t,e){const n=Oc(t.getEditorState()),o=[];for(const t of e){const e=n.get(t);e&&o.push(e);}if(0===o.length)return;t.update(()=>{for(const t of o)for(const e of t.keys()){const t=Vs(e);t&&t.markDirty();}},null===t._pendingEditorState?{tag:xo}:void 0);}(this,o.map(t=>t.klass.getType())),()=>{o.forEach(t=>t.transforms.delete(e));}}hasNode(t){return this._nodes.has(t.getType())}hasNodes(t){return t.every(this.hasNode.bind(this))}dispatchCommand(t,e){return kl(this,t,e)}getDecorators(){return this._decorators}getRootElement(){return this._rootElement}getKey(){return this._key}setRootElement(t){const e=this._rootElement;if(t!==e){const n=ml(this._config.theme,"root"),o=this._pendingEditorState||this._editorState;if(this._rootElement=t,hs(this,e,t,o,{preserveUpdateQueue:true}),null!==e&&(this._config.disableEvents||co$1(e),null!=n&&e.classList.remove(...n)),null!==t){const e=Dl(t),o=t.style;o.userSelect="text",o.whiteSpace="pre-wrap",o.wordBreak="break-word",t.setAttribute("data-lexical-editor","true"),this._window=e,this._dirtyType=2,gt$3(this),this._updateTags.add(xo),ki(this),this._config.disableEvents||function(t,e){const n=t.ownerDocument;Ln$1.set(t,n);let o=Kn$1.get(n);void 0===o&&(o={editors:new Set,hasShadowEditor:void 0},Kn$1.set(n,o)),o.editors.add(e),o.hasShadowEditor=void 0,t.__lexicalEditor=e;const r=no$1(t);r.push(zn$1.register(n));for(let n=0;n<In$1.length;n++){const[o,i]=In$1[n],s="function"==typeof i?t=>{so$1(t)||(io$1(t),(e.isEditable()||"click"===o)&&i(t,e));}:t=>{if(so$1(t))return;io$1(t);const n=e.isEditable();switch(o){case "cut":return n&&kl(e,Tn$1,t);case "copy":return kl(e,vn$1,t);case "paste":return n&&kl(e,je$2,t);case "dragstart":return n&&kl(e,xn$1,t);case "dragover":return n&&kl(e,Cn$2,t);case "dragend":return n&&kl(e,Sn$1,t);case "focus":return n&&kl(e,On$2,t);case "blur":return n&&kl(e,Mn$2,t);case "drop":return n&&kl(e,yn$1,t)}};r.push(Pn$1(t,o,s));}}(t,this),null!=n&&t.classList.add(...n);}else this._window=null,this._updateTags.add(xo),ki(this);bi("root",this,false,t,e);}}getElementByKey(t){return this._keyToDOMMap.get(t)||null}getEditorState(){return this._editorState}setEditorState(e,n){e.isEmpty()&&t(38);let o=e;o._readOnly&&(o=Bi(e),o._selection=e._selection?e._selection.clone():null),ht$5(this);const r=this._pendingEditorState,i=void 0!==n?n.tag:null;null===r||r.isEmpty()||(null!=i&&this._updateTags.add(i),ki(this)),this._pendingEditorState=o,this._dirtyType=2,this._dirtyElements.set("root",false),this._compositionKey=null,this._slotsUsed=this._slotsUsed||e._slotsUsed,Mi(this,()=>{if(i&&this._updateTags.add(i),e._parsed)for(const[t,e]of o._nodeMap.entries())Pi(e)?this._dirtyElements.set(t,true):this._dirtyLeaves.add(t);},{discrete:!this._updating||void 0});}parseEditorState(t,e){return function(t,e,n){const o=Ri(),r=ei,i=oi,s=ni,l=e._dirtyElements,c=e._dirtyLeaves,a=e._cloneNotNeeded,u=e._dirtyType;e._dirtyElements=new Map,e._dirtyLeaves=new Set,e._cloneNotNeeded=new Set,e._dirtyType=0,ei=o,oi=false,ni=e,Ss(null);try{const r=e._nodes;vi(t.root,r),n&&n(),o._readOnly=!0,o._parsed=!0;}catch(t){t instanceof Error&&e._onError(t);}finally{e._dirtyElements=l,e._dirtyLeaves=c,e._cloneNotNeeded=a,e._dirtyType=u,ei=r,oi=i,ni=s;}return o}("string"==typeof t?JSON.parse(t):t,this,e)}read(...t){const[e,n]=1===t.length?["force-commit",t[0]]:t;"force-commit"===e&&ki(this);return ("pending"===e?this._pendingEditorState||this._editorState:this.getEditorState()).read(n,{editor:this})}update(t,e){!function(t,e,n){t._updating?t._updates.push([e,n]):Oi(t,e,n);}(this,t,e);}focus(t,e={}){const n=this._rootElement;null!==n&&(n.setAttribute("autocapitalize","off"),Mi(this,()=>{const o=Lr(),r=tl();null!==o?o.dirty||el(o.clone()):0!==r.getChildrenSize()&&("rootStart"===e.defaultSelection?r.selectStart():r.selectEnd()),Ol("focus"),Ml(()=>{n.removeAttribute("autocapitalize"),t&&t();});}),null===this._pendingEditorState&&n.removeAttribute("autocapitalize"));}blur(){const t=this._rootElement;null!==t&&t.blur();const e=Hl(this._window);null!==e&&e.removeAllRanges();}isEditable(){return this._editable}setEditable(t){this._editable!==t&&(this._editable=t,bi("editable",this,true,t),this._slotsUsed&&this.update(()=>_i()));}toJSON(){return {editorState:this._editorState.toJSON()}}}xs.version=Z$4;let Cs=null;function Ss(t){Cs=t;}let vs=1;function ks(e,n){const o=bs(e,n);return void 0===o&&t(30,n),o}function bs(t,e){return t._nodes.get(e)}const Ns="function"==typeof queueMicrotask?queueMicrotask:t=>{Promise.resolve().then(t);};function ws(t,e){const n=void 0!==e?e:(()=>{const e=t.getRootNode();return Ks(e)||jl(e)?sc(e):null})();if(!dc(n))return  false;if(n.hasAttribute("data-lexical-slot"))return  false;const o=Xs(n),r=n.nodeName;return po(o)&&("INPUT"===r||"TEXTAREA"===r||"true"===n.contentEditable&&null==Ds(n))}function Os(t,e,n){const o=t.getRootElement();if(!o)return  false;try{if(!e||!o.contains(e)||!o.contains(n))return !1}catch(t){return  false}return As(e)===t&&t.read("latest",()=>!ws(e))}function Ms(t){return t instanceof xs}function As(t){let e=t;for(;null!=e;){const t=Ds(e);if(Ms(t))return t;e=Nl(e);}return null}function Ds(t){return t?t.__lexicalEditor:null}function Fs(t){return er(t)||t.isToken()}function Is(t){return Fs(t)||t.isSegmented()}function Ls(t){return hc(t)&&3===t.nodeType}function Ks(t){return hc(t)&&9===t.nodeType}function zs(t){let e=t;for(;null!=e;){if(Ls(e))return e;e=e.firstChild;}return null}function Bs(t,e,n){const o=z$1[e];if(null!==n&&(t&o)===(n&o))return t;let r=t^o;return "subscript"===e?r&=-65:"superscript"===e?r&=-33:"lowercase"===e?(r&=-513,r&=-1025):"uppercase"===e?(r&=-257,r&=-1025):"capitalize"===e&&(r&=-257,r&=-513),r}function Rs(t){return Xo(t)||qi(t)||Li(t)}function Ws(t,e){const n=function(){const t=Cs;return Cs=null,t}();if(null!=(e=e||n&&n.__key))return void(t.__key=e);ui(),fi();const o=gi(),r=di(),i=""+vs++;r._nodeMap.set(i,t),Pi(t)?o._dirtyElements.set(i,true):o._dirtyLeaves.add(i),o._cloneNotNeeded.add(i),0===o._dirtyType&&(o._dirtyType=1),t.__key=i;}function Us(e){null!==Xc(e)&&t(380,e.__key,String(Xc(e)));const n=e.getParent();if(null!==n){const t=e.getWritable(),o=n.getWritable(),r=e.getPreviousSibling(),i=e.getNextSibling(),s=null!==i?i.__key:null,l=null!==r?r.__key:null,c=null!==r?r.getWritable():null,a=null!==i?i.getWritable():null;null===r&&(o.__first=s),null===i&&(o.__last=l),null!==c&&(c.__next=s),null!==a&&(a.__prev=l),t.__prev=null,t.__next=null,t.__parent=null,o.__size--;}}function Hs(e){fi(),ho$1(e)&&t(323,e.__key,e.__type);const n=e.getLatest(),o=null!==n.__parent?n.__parent:Gc(n)?n.__slotHost:null,r=di(),i=gi(),s=r._nodeMap,l=i._dirtyElements;null!==o&&function(t,e,n){let o=t;for(;null!==o;){if(n.has(o))return;const t=e.get(o);if(void 0===t)break;n.set(o,false),o=null!==t.__parent?t.__parent:Gc(t)?t.__slotHost:null;}}(o,s,l);const c=n.__key;0===i._dirtyType&&(i._dirtyType=1),Pi(e)?l.set(c,true):i._dirtyLeaves.add(c);}function Js(t){ui();const e=gi(),n=e._compositionKey;if(t!==n){if(e._compositionKey=t,null!==n){const t=Vs(n);null!==t&&t.getWritable();}if(null!==t){const e=Vs(t);null!==e&&e.getWritable();}}}function js(){if(ai())return null;return gi()._compositionKey}function Vs(t,e){const n=(e||di())._nodeMap.get(t);return void 0===n?null:n}function qs(t,e){const n=Gs(t,gi());return void 0!==n?Vs(n,e):null}function Ys(t,e,n){t[`__lexicalKey_${e._key}`]=n;}function Gs(t,e){return t[`__lexicalKey_${e._key}`]}function Xs(t,e){let n=t;for(;null!=n;){const t=qs(n,e);if(null!==t)return t;n=Nl(n);}return null}function Qs(t){const e=t._decorators,n=Object.assign({},e);return t._pendingDecorators=n,n}function Zs(t){return t.read(()=>tl().getTextContent())}function tl(){return di()._nodeMap.get("root")}function el(t){ui();const e=di();null!==t&&(t.dirty=true,t.setCachedNodes(null),cr(t)&&gi()._slotsUsed&&Er(t)),e._selection=t;}function nl(){ui();ht$5(gi());}function ol(t){const e=function(t,e){let n=t;for(;null!=n;){const t=Gs(n,e);if(void 0!==t)return t;n=Nl(n);}return null}(t,gi());return null===e?null:Vs(e)}function rl(t){return /[\uD800-\uDBFF][\uDC00-\uDFFF]/g.test(t)}function il(t){const e=[];for(let n=t;null!==n;n=n._parentEditor)e.push(n);return e}function sl(){return Math.random().toString(36).replace(/[^a-z]+/g,"").substring(0,5)}function ll(t){return Ls(t)?t.nodeValue:null}function cl(t,e,n){const o=Hl(Pl(e));if(null===o)return;const r=tc(o,e._rootElement),i=r.anchorNode;let{anchorOffset:s,focusOffset:l}=r;if(null!==i){let e=ll(i);const o=Xs(i);if(null!==e&&Xo(o)){if((e===A||e===P)&&n){const t=n.length;e=n,s=t,l=t;}null!==e&&al(o,e,s,l,t);}}}function al(t,e,n,o,r){let i=t;if(i.isAttached()&&(r||!i.isDirty())){const s=i.isComposing();if(i.isToken()&&s)return;let c=e;if((s||r)&&(e.endsWith(A)&&(c=e.slice(0,-A.length)),r)){const t=P;let e;for(;-1!==(e=c.indexOf(t));)c=c.slice(0,e)+c.slice(e+t.length),null!==n&&n>e&&(n=Math.max(e,n-t.length)),null!==o&&o>e&&(o=Math.max(e,o-t.length));}const u=i.getTextContent();if(r||c!==u){const e=Lr();if(""===c){if(Js(null),a||l||d)i.remove();else {const t=gi();ul(i,"",e),setTimeout(()=>{t.update(()=>{i.isAttached()&&""===i.getTextContent()&&i.remove();});},20);}return}const r=i.getParent(),u=Kr(),f=i.getTextContentSize(),h=js(),g=i.getKey();if(i.isToken()&&!s||null!==h&&g===h&&!s||cr(u)&&(null!==r&&!r.canInsertTextBefore()&&0===u.anchor.offset||u.anchor.key===t.__key&&0===u.anchor.offset&&!i.canInsertTextBefore()&&!s||u.focus.key===t.__key&&u.focus.offset===f&&!i.canInsertTextAfter()&&!s))return void i.markDirty();if(!cr(e)||null===n||null===o)return void ul(i,c,e);if(e.setTextNodeRange(i,n,i,o),i.isSegmented()){const t=Go(i.getTextContent());i.replace(t),i=t;}ul(i,c,e);}}}function ul(t,e,n){if(t.setTextContent(e),cr(n)){const e=t.getKey();let o=false;for(const r of ["anchor","focus"]){const i=n[r];"text"===i.type&&i.key===e&&(i.offset=Fa(t,i.offset,"clamp"),o=true);}o&&(n._cachedNodes=null,n._cachedIsBackward=null);}}function fl(t,e,n){const o=e[n]||false;return "any"===o||o===t[n]}function dl(t,e){return fl(t,e,"altKey")&&fl(t,e,"ctrlKey")&&fl(t,e,"shiftKey")&&fl(t,e,"metaKey")}function hl(t,e,n){if(!dl(t,n))return  false;if(t.key.toLowerCase()===e.toLowerCase())return  true;if(e.length>1)return  false;if(1===t.key.length&&t.key.charCodeAt(0)<=127)return  false;if(t.code.startsWith("Digit")&&/^\d$/.test(e))return t.code===`Digit${e}`;const o="Key"+e.toUpperCase();return t.code===o}const gl={ctrlKey:!r,metaKey:r},_l={altKey:r,ctrlKey:!r};function pl(t){return "Backspace"===t.key}function yl(t){const e=tl();if(cr(t)){const e=t.anchor,n=t.focus,o=e.getNode();if(zi(o))return e.set(o.getKey(),0,"element"),n.set(o.getKey(),o.getChildrenSize(),"element"),It$5(t),t;const r=o.getTopLevelElementOrThrow(),i=r.getParent();if(null===i)return Pi(r)&&(e.set(r.getKey(),0,"element"),n.set(r.getKey(),r.getChildrenSize(),"element"),It$5(t)),t;const s=i;return e.set(s.getKey(),0,"element"),n.set(s.getKey(),s.getChildrenSize(),"element"),It$5(t),t}{const t=e.select(0,e.getChildrenSize());return el(It$5(t)),t}}function ml(t,e){ void 0===t.__lexicalClassNameCache&&(t.__lexicalClassNameCache={});const n=t.__lexicalClassNameCache,o=n[e];if(void 0!==o)return o;const r=t[e];if("string"==typeof r){const t=Su(r);return n[e]=t,t}return r}function xl(e,n,o,r,i){if(0===o.size)return;const s=r.__type,l=r.__key,c=n.get(s);void 0===c&&t(33,s);const a=c.klass;let u=e.get(a);void 0===u&&(u=new Map,e.set(a,u));const f=u.get(l),d="destroyed"===f&&"created"===i;(void 0===f||d)&&u.set(l,d?"updated":i);}function Sl(t,e,n){const o=t.getParent();let r=n,i=t;return null!==o&&(0===n?(r=i.getIndexWithinParent(),i=o):e),i.getChildAtIndex(r-1)}function vl(t,e){const n=t.offset;if("element"===t.type){return Sl(t.getNode(),e,n)}{const o=t.getNode();if(0===n||!e){const t=o.getPreviousSibling();return null===t?Sl(o.getParentOrThrow(),e,o.getIndexWithinParent()+(0)):t}}return null}function Tl(t){const e=Pl(t).event,n=e&&e.inputType;return "insertFromPaste"===n||"insertFromPasteAsQuotation"===n}function kl(t,e,n){return Ni(t,e,n,t)}function bl(e,n){const o=e._keyToDOMMap.get(n);return void 0===o&&t(75,n),o}function Nl(t){const e=t.assignedSlot||t.parentElement;if(null!==e)return e;const n=t.parentNode;return jl(n)?n.host:null}function wl(t){return Ks(t)?t:dc(t)?t.ownerDocument:null}function Ol(t){ui();gi()._updateTags.add(t);}function Ml(t){ui();gi()._deferred.push(t);}function Al(t,e){let n=t.getParent();for(;null!==n;){if(n.is(e))return  true;n=n.getParent();}return  false}function Dl(t){const e=wl(t);return e?e.defaultView:null}function Pl(e){const n=e._window;return null===n&&t(78),n}function Fl(t){return Pi(t)&&t.isInline()||Li(t)&&t.isInline()}function Il(t){let e=t.getLatest();for(;null!==e;){if(null!==Xc(e)&&Pi(e))return e;const t=e.getParentOrThrow();if(Kl(t))return t;e=t;}return e}function Ll(t){return Pi(t)&&t.isShadowRoot()}function Kl(t){return zi(t)||Ll(t)}function zl(t,e=false){const n=t.constructor.clone(t);return Ws(n,null),n.afterCloneFrom(t),e||n.resetOnCopyNodeFrom(t),n}function Bl(e){const n=gi(),o=e.getType(),r=bs(n,o);void 0===r&&t(200,e.constructor.name,o);const{replace:i,replaceWithKlass:s}=r;if(null!==i){const n=i(e),r=n.constructor;return null!==s?n instanceof s||t(201,s.name,s.getType(),r.name,r.getType(),e.constructor.name,o):n instanceof e.constructor&&r!==e.constructor||t(202,r.name,r.getType(),e.constructor.name,o),n.__key===e.__key&&t(203,e.constructor.name,o,r.name,r.getType()),n}return e}function Rl(e,n){!zi(e.getParent())||Pi(n)||Li(n)||t(99);}function Wl(e){const n=Vs(e);return null===n&&t(63,e),n}function Ul(t){if(!t||t.isInline())return  false;if(Li(t))return  true;if(Pi(t)){if(t.isShadowRoot()){const e=t.getParent();return !(Pi(e)&&e.isShadowRoot())}return !t.canBeEmpty()}return  false}function $l(t,e,n){n.style.removeProperty("caret-color"),e._blockCursorElement=null;const o=t.parentElement;null!==o&&o.removeChild(t);}function Hl(t){return n?(t||window).getSelection():null}function Jl(t){const e=Dl(t);return e?e.getSelection():null}function jl(t){return gc(t)&&"host"in t}const Vl=[];function ql(t){const e=t.getRootNode();if(e===t||!jl(e))return Vl;const n=[e];let o=e.host;for(;;){const t=o.getRootNode();if(t===o||!jl(t))break;n.push(t),o=t.host;}return n}function*Yl(t){const e=[t];let n;for(;n=e.pop();){yield*n.querySelectorAll('[data-lexical-editor="true"]');const t=(Ks(n)?n:n.ownerDocument).createTreeWalker(n,NodeFilter.SHOW_ELEMENT);let o;for(;o=t.nextNode();)o.shadowRoot&&e.push(o.shadowRoot);}}function Gl(t){return null!==t?t.ownerDocument:document}function Xl(){const t=yi();return Gl(null!==t?t._rootElement:null)}function Ql(t,e){if(null===e||"function"!=typeof t.getComposedRanges)return null;const n=ql(e);if(0===n.length)return null;const o=t.getComposedRanges;try{const e=o.call(t,{shadowRoots:n})[0];if(void 0!==e)return e}catch(t){}try{const e=o.apply(t,n)[0];if(void 0!==e)return e}catch(t){}return null}function Zl(t,e){const n=Ql(t,e);if(null!==n){const t=nc(n);if(null!==t)return t}return t.rangeCount>0?t.getRangeAt(0):null}function tc(t,e){const n=Ql(t,e);return null===n?t:oc(n,rc(t))}function nc(t){const e=t.startContainer.ownerDocument;if(null===e)return null;const n=e.createRange();try{return n.setStart(t.startContainer,t.startOffset),n.setEnd(t.endContainer,t.endOffset),n}catch(t){return null}}function oc(t,e){const{startContainer:n,startOffset:o,endContainer:r,endOffset:i}=t;return "backward"===e?{anchorNode:r,anchorOffset:i,direction:e,focusNode:n,focusOffset:o}:{anchorNode:n,anchorOffset:o,direction:e,focusNode:r,focusOffset:i}}function rc(t){return t.direction}function ic(t){const e=t.getRootNode();return Ks(e)||jl(e)?e.activeElement:null}function sc(t){let e=t.activeElement;for(;null!==e&&null!==e.shadowRoot;){const t=e.shadowRoot.activeElement;if(null===t)break;e=t;}return e}function lc(t){const e=t.target;if(null!==e&&dc(e)&&null!==e.shadowRoot&&"function"==typeof t.composedPath){const e=t.composedPath();if(e.length>0)return e[0]}return e}function ac(t){return dc(t)&&"A"===t.tagName}function uc(t){return dc(t)&&"TR"===t.tagName}function dc(t){return hc(t)&&1===t.nodeType}function hc(t){return "object"==typeof t&&null!==t&&"nodeType"in t&&"number"==typeof t.nodeType}function gc(t){return hc(t)&&11===t.nodeType}const _c=/^(a|abbr|acronym|b|cite|code|del|em|i|ins|kbd|label|mark|output|q|ruby|s|samp|span|strong|sub|sup|time|u|tt|var|#text)$/i;function pc(t){return !(!dc(t)||!t.style.display.startsWith("inline"))||_c.test(t.nodeName)}const yc=/^(address|article|aside|blockquote|canvas|dd|div|dl|dt|fieldset|figcaption|figure|footer|form|h1|h2|h3|h4|h5|h6|header|hr|li|main|nav|noscript|ol|p|pre|section|table|td|tfoot|ul|video)$/i;function mc(t){return (!dc(t)||!t.style.display.startsWith("inline"))&&yc.test(t.nodeName)}function xc(t){if(Li(t)&&!t.isInline())return  true;if(!Pi(t)||Kl(t))return  false;const e=t.getFirstChild(),n=null===e||qi(e)||Xo(e)||e.isInline();return !t.isInline()&&false!==t.canBeEmpty()&&n}function Cc(){return gi()}function Sc(t=Cc()){return t._config.dom||_s}function vc(e,n,o=Cc()){const r=Sc(o).$getDOMSlot(e,n,o);return Pi(e)&&(bc(r)||t(344,e.getKey(),e.getType())),r}function bc(t){return t instanceof G$1}function Nc(t,e,n=Cc()){return zs(vc(t,e,n).element)}const wc=new WeakMap,Ec=new Map;function Oc(e){if(!e._readOnly&&e.isEmpty())return Ec;e._readOnly||t(192);let n=wc.get(e);return n||(n=function(t){const e=new Map;for(const[n,o]of t._nodeMap){const t=o.__type;let r=e.get(t);r||(r=new Map,e.set(t,r)),r.set(n,o);}return e}(e),wc.set(e,n)),n}function Mc(t){const e=t.constructor.clone(t);return e.afterCloneFrom(t),e}function Ac(t){return (e=Mc(t))[fo]=true,e;var e;}function Dc(t,e){const n=t.getAttribute("data-lexical-indent");if(null!==n){const t=parseInt(n,10);if(Number.isFinite(t)&&t>=0)return void e.setIndent(t)}const o=parseInt(t.style.paddingInlineStart,10)||0,r=Math.round(o/40);e.setIndent(r);}function Pc(t,e){const n=e.getAttribute("dir");return "ltr"===n||"rtl"===n?t.setDirection(n):t}function Fc(t,e){const n=e.style.textAlign;return n&&n in R$1?t.setFormat(n):t}function Ic(t,e){t.__lexicalUnmanaged=true,e&&void 0!==e.captureSelection&&(t.__lexicalCapturedSelection=e.captureSelection);}function Lc(t){return  true===t.__lexicalUnmanaged}function Kc(t,e=Cc()){const n=e.isEditable();t.contentEditable=n?"true":"false",n?t.__lexicalEditor=e:delete t.__lexicalEditor;}function zc(t,e){let n=t;for(;null!=n;){if(true===n.__lexicalCapturedSelection)return  true;if(dc(n)&&n.hasAttribute("data-lexical-slot"))return  false;if(void 0!==Gs(n,e))return  false;n=Nl(n);}return  false}function Bc(t,e){return function(t,e){return Object.prototype.hasOwnProperty.call(t,e)}(t,e)&&t[e]!==_o[e]}const Rc=new WeakMap;function Wc(e){const n=Rc.get(e);if(n)return n;const o=null!=e.prototype&&J in e.prototype?e.prototype[J]():void 0,r=function(e){if(!(e===_o||e.prototype instanceof _o)){let n="<unknown>",o="<unknown>";try{n=e.getType();}catch(t){}try{xs.version&&(o=JSON.parse(xs.version));}catch(t){}t(290,e.name,n,o);}return e===Ii||e===Di||e===_o}(e),i=!r&&Bc(e,"getType")?e.getType():void 0;let s,l=i;if(o)if(i)s=o[i];else {for(const[t,e]of Object.entries(o))l=t,s=e;if(!s)for(const t of Object.getOwnPropertySymbols(o)){const e=o[t];if(e){s=e;break}}}if(!r&&l&&(Bc(e,"getType")||(e.getType=()=>l),Bc(e,"clone")||(e.clone=t=>(Ss(t),new e)),Bc(e,"importJSON")||(e.importJSON=s&&s.$importJSON||(t=>(new e).updateFromJSON(t))),!Bc(e,"importDOM")&&s)){const{importDOM:t}=s;t&&(e.importDOM=()=>t);}const c={klass:e,ownNodeConfig:s,ownNodeType:l};return Rc.set(e,c),c}function*Uc(t){for(let e=t;e&&(e===_o||po(e.prototype));){const t=Wc(e);yield t,e=t.ownNodeConfig&&t.ownNodeConfig.extends||Vc(e);}}function Hc(t){const e=Cc();ui();return new(e.resolveRegisteredNodeAfterReplacements(e.getRegisteredNode(t)).klass)}const Jc=(t,e)=>{let n=t;for(;null!=n&&!zi(n);){if(e(n))return n;n=n.getParent();}return null};function jc(e,n){const o=[];let r=e.__first;for(;null!==r;){const e=null===n?Vs(r):n.get(r);null==e&&t(174),o.push(r),r=e.__next;}return o}function Vc(t){const e=Object.getPrototypeOf(t);if("function"==typeof e&&e!==Function.prototype)return e;const n=t.prototype&&Object.getPrototypeOf(t.prototype);return n?n.constructor:null}const qc=new Map;function Yc(t){return Pi(t)||Li(t)}function Gc(t){return Pi(t)||Li(t)}function Xc(t){const e=t.getLatest();return Gc(e)?e.__slotHost:null}function Qc(e){const n=Xc(e);if(null===n)return null;const o=Vs(n);return Pi(o)||Li(o)||t(370),o}function Zc(t){const e=Qc(t);if(null===e)return null;const n=t.getLatest().__key;for(const[t,o]of ea(e))if(o===n)return t;return null}function ta(t){let e=t.getLatest();for(;null!==e;){if(null!==Xc(e))return e;e=e.getParent();}return null}function ea(t){const e=t.getLatest();return Yc(e)&&null!==e.__slots?e.__slots:qc}function na(t){return Array.from(ea(t).keys())}function oa(t,e){const n=ea(t).get(e);return void 0===n?null:Vs(n)}const ra=["__proto__","constructor","prototype"],ia=Symbol("slotMapOwner");function sa(t){let e=t.__slots;return null!==e&&e[ia]===t||(e=new Map(e),e[ia]=t,t.__slots=e),e}const la=new WeakMap,ca=[];function aa(t){for(const{ownNodeConfig:e}of Uc(t)){const t=e&&e.slots;if(t)return t}return ca}function ua(t){let e="";for(const n of na(t)){const o=oa(t,n);null!==o&&(e+=o.getTextContent());}return e}function fa(t,e,n){const o=n.get(t),r=n.get(e);return void 0!==o?void 0!==r?o-r:-1:void 0!==r?1:t<e?-1:t>e?1:0}function da(e){const n=e.__slots;if(null===n||n.size<2)return;const o=function(e){let n=la.get(e);if(void 0===n){const o=aa(e),r=new Map;for(const n of o)ra.includes(n)&&t(371,e.name,n),r.has(n)&&t(372,e.name,n),r.set(n,r.size);n=r,la.set(e,n);}return n}(e.constructor);let r=null,i=true;for(const t of n.keys()){if(null!==r&&fa(r,t,o)>0){i=false;break}r=t;}if(i)return;const s=Array.from(n).sort(([t],[e])=>fa(t,e,o));n.clear();for(const[t,e]of s)n.set(t,e);}function ha(e,n,o){"__proto__"!==n&&"constructor"!==n&&"prototype"!==n||t(373,n);const r=e.getLatest();if(null!==r.__slots&&r.__slots.get(n)===o.getLatest().__key)return r;(!Pi(o)&&!Li(o)||o.isInline())&&t(374,o.__key);const i=e.getWritable(),s=sa(i),l=s.get(n);void 0!==l&&pa(l);const c=o.getWritable(),a=Qc(c);if(null!==a){const t=Zc(c);null!==t&&sa(a.getWritable()).delete(t),c.__slotHost=null;}return Us(c),c.__slotHost=i.__key,s.set(n,c.__key),da(i),function(){const t=Cc();t._slotsUsed=true,t._pendingEditorState&&(t._pendingEditorState._slotsUsed=true);}(),i}function ga(t,e){const n=t.getWritable();if(null===n.__slots)return n;const o=n.__slots.get(e);return void 0!==o&&(pa(o),sa(n).delete(e)),n}function _a(t,e){}function pa(e){const n=Vs(e);if(null===n)return;const o=n.getWritable();Gc(o)||t(377,e),o.__slotHost=null,o.remove();}const ya={next:"previous",previous:"next"};class ma{origin;constructor(t){this.origin=t;}[Symbol.iterator](){return Ja({hasNext:wa,initial:this.getAdjacentCaret(),map:t=>t,step:t=>t.getAdjacentCaret()})}getAdjacentCaret(){return Da(this.getNodeAtCaret(),this.direction)}getSiblingCaret(){return Da(this.origin,this.direction)}remove(){const t=this.getNodeAtCaret();return t&&t.remove(),this}replaceOrInsert(t,e){const n=this.getNodeAtCaret();return t.is(this.origin)||t.is(n)||(null===n?this.insert(t):n.replace(t,e)),this}splice(e,n,o="next"){const r=o===this.direction?n:Array.from(n).reverse();let i=this;const s=this.getParentAtCaret(),l=new Map;for(let t=i.getAdjacentCaret();null!==t&&l.size<e;t=t.getAdjacentCaret()){const e=t.origin.getWritable();l.set(e.getKey(),e);}for(const e of r){if(l.size>0){const n=i.getNodeAtCaret();if(n)if(l.delete(n.getKey()),l.delete(e.getKey()),n.is(e)||i.origin.is(e));else {const t=e.getParent();t&&t.is(s)&&e.remove(),n.replace(e);}else null===n&&t(263,Array.from(l).join(" "));}else i.insert(e);i=Da(e,this.direction);}for(const t of l.values())t.remove();return this}}class xa extends ma{type="child";getLatest(){const t=this.origin.getLatest();return t===this.origin?this:La(t,this.direction)}getParentCaret(t="root"){return Da(va(this.getParentAtCaret(),t),this.direction)}getFlipped(){const t=Sa(this.direction);return Da(this.getNodeAtCaret(),t)||La(this.origin,t)}getParentAtCaret(){return this.origin}getChildCaret(){return this}isSameNodeCaret(t){return t instanceof xa&&this.direction===t.direction&&this.origin.is(t.origin)}isSamePointCaret(t){return this.isSameNodeCaret(t)}}const Ca={root:zi,shadowRoot:Kl};function Sa(t){return ya[t]}function va(t,e="root"){return null===t||Ca[e](t)?null:null===Xc(t)?t:null}class Ta extends ma{type="sibling";getLatest(){const t=this.origin.getLatest();return t===this.origin?this:Da(t,this.direction)}getSiblingCaret(){return this}getParentAtCaret(){return this.origin.getParent()}getChildCaret(){return Pi(this.origin)?La(this.origin,this.direction):null}getParentCaret(t="root"){return Da(va(this.getParentAtCaret(),t),this.direction)}getFlipped(){const t=Sa(this.direction);return Da(this.getNodeAtCaret(),t)||La(this.origin.getParentOrThrow(),t)}isSamePointCaret(t){return t instanceof Ta&&this.direction===t.direction&&this.origin.is(t.origin)}isSameNodeCaret(t){return (t instanceof Ta||t instanceof ka)&&this.direction===t.direction&&this.origin.is(t.origin)}}class ka extends ma{type="text";offset;constructor(t,e){super(t),this.offset=e;}getLatest(){const t=this.origin.getLatest();return t===this.origin?this:Pa(t,this.direction,this.offset)}getParentAtCaret(){return this.origin.getParent()}getChildCaret(){return null}getParentCaret(t="root"){return Da(va(this.getParentAtCaret(),t),this.direction)}getFlipped(){return Pa(this.origin,Sa(this.direction),this.offset)}isSamePointCaret(t){return t instanceof ka&&this.direction===t.direction&&this.origin.is(t.origin)&&this.offset===t.offset}isSameNodeCaret(t){return (t instanceof Ta||t instanceof ka)&&this.direction===t.direction&&this.origin.is(t.origin)}getSiblingCaret(){return Da(this.origin,this.direction)}}function ba(t){return t instanceof ka}function wa(t){return t instanceof Ta}function Ea(t){return t instanceof xa}const Oa={next:class extends ka{direction="next";getNodeAtCaret(){return this.origin.getNextSibling()}insert(t){return this.origin.insertAfter(t),this}},previous:class extends ka{direction="previous";getNodeAtCaret(){return this.origin.getPreviousSibling()}insert(t){return this.origin.insertBefore(t),this}}},Ma={next:class extends Ta{direction="next";getNodeAtCaret(){return this.origin.getNextSibling()}insert(t){return this.origin.insertAfter(t),this}},previous:class extends Ta{direction="previous";getNodeAtCaret(){return this.origin.getPreviousSibling()}insert(t){return this.origin.insertBefore(t),this}}},Aa={next:class extends xa{direction="next";getNodeAtCaret(){return this.origin.getFirstChild()}insert(t){return this.origin.splice(0,0,[t]),this}},previous:class extends xa{direction="previous";getNodeAtCaret(){return this.origin.getLastChild()}insert(t){return this.origin.splice(this.origin.getChildrenSize(),0,[t]),this}}};function Da(t,e){return t?new Ma[e](t):null}function Pa(t,e,n){return t?new Oa[e](t,Fa(t,n)):null}function Fa(t,n,o="error"){const r=t.getTextContentSize();let i="next"===n?r:"previous"===n?0:n;return (i<0||i>r)&&("clamp"!==o&&e(284,String(n),String(r),t.getKey()),i=i<0?0:r),i}function Ia(t,e){return new Ra(t,e)}function La(t,e){return Pi(t)?new Aa[e](t):null}function Ka(t){return t&&t.getChildCaret()||t}function za(t){return t&&Ka(t.getAdjacentCaret())}class Ba{type="node-caret-range";direction;anchor;focus;constructor(t,e,n){this.anchor=t,this.focus=e,this.direction=n;}getLatest(){const t=this.anchor.getLatest(),e=this.focus.getLatest();return t===this.anchor&&e===this.focus?this:new Ba(t,e,this.direction)}isCollapsed(){return this.anchor.isSamePointCaret(this.focus)}getTextSlices(){const t=t=>{const e=this[t].getLatest();return ba(e)?function(t,e){const{direction:n,origin:o}=t,r=Fa(o,"focus"===e?Sa(n):n);return Ia(t,r-t.offset)}(e,t):null},e=t("anchor"),n=t("focus");if(e&&n){const{caret:t}=e,{caret:o}=n;if(t.isSameNodeCaret(o))return [Ia(t,o.offset-t.offset),null]}return [e,n]}iterNodeCarets(t="root"){const e=ba(this.anchor)?this.anchor.getSiblingCaret():this.anchor.getLatest(),n=this.focus.getLatest(),o=ba(n),r=e=>e.isSameNodeCaret(n)?null:za(e)||e.getParentCaret(t);return Ja({hasNext:t=>null!==t&&!(o&&n.isSameNodeCaret(t)),initial:e.isSameNodeCaret(n)?null:r(e),map:t=>t,step:r})}[Symbol.iterator](){return this.iterNodeCarets("root")}}class Ra{type="slice";caret;distance;constructor(t,e){this.caret=t,this.distance=e;}getSliceIndices(){const{distance:t,caret:{offset:e}}=this,n=e+t;return n<e?[n,e]:[e,n]}getTextContent(){const[t,e]=this.getSliceIndices();return this.caret.origin.getTextContent().slice(t,e)}getTextContentSize(){return Math.abs(this.distance)}removeTextSlice(){const{caret:{origin:t,direction:e}}=this,[n,o]=this.getSliceIndices(),r=t.getTextContent();return Pa(t.setTextContent(r.slice(0,n)+r.slice(o)),e,n)}}function Ua(t){return Ha(t,Da(tl(),t.direction))}function $a(t){return Ha(t,t)}function Ha(e,n){return e.direction!==n.direction&&t(265),new Ba(e,n,e.direction)}function Ja(t){const{initial:e,hasNext:n,step:o,map:r}=t;let i=e;return {[Symbol.iterator](){return this},next(){if(!n(i))return {done:true,value:void 0};const t={done:false,value:r(i)};return i=o(i),t}}}function ja(e,n){const o=Ga(e.origin,n.origin);switch(null===o&&t(275,e.origin.getKey(),n.origin.getKey()),o.type){case "same":{const t="text"===e.type,o="text"===n.type;return t&&o?function(t,e){return Math.sign(t-e)}(e.offset,n.offset):e.type===n.type?0:t?-1:o?1:"child"===e.type?-1:1}case "ancestor":return "child"===e.type?-1:1;case "descendant":return "child"===n.type?1:-1;case "branch":return Va(o)}}function Va(t){const{a:e,b:n}=t,o=e.__key,r=n.__key;let i=e,s=n;for(;i&&s;i=i.getNextSibling(),s=s.getNextSibling()){if(i.__key===r)return  -1;if(s.__key===o)return 1}return null===i?1:-1}function qa(t,e){return e.is(t)}function Ya(t){return Pi(t)?[t.getLatest(),null]:[t.getParent(),t.getLatest()]}function Ga(e,n){if(e.is(n))return {commonAncestor:e,type:"same"};const o=new Map;for(let[t,n]=Ya(e);t;n=t,t=t.getParent())o.set(t,n);for(let[r,i]=Ya(n);r;i=r,r=r.getParent()){const s=o.get(r);if(void 0!==s)return null===s?(qa(e,r)||t(276),{commonAncestor:r,type:"ancestor"}):null===i?(qa(n,r)||t(277),{commonAncestor:r,type:"descendant"}):((Pi(s)||qa(e,s))&&(Pi(i)||qa(n,i))&&r.is(s.getParent())&&r.is(i.getParent())||t(278),{a:s,b:i,commonAncestor:r,type:"branch"})}return null}function Xa(e,n){const{type:o,key:r,offset:i}=e,s=Wl(e.key);return "text"===o?(Xo(s)||t(266,s.getType(),r),Pa(s,n,i)):(Pi(s)||t(267,s.getType(),r),uu(s,e.offset,n))}function Qa(e,n){const{origin:o,direction:r}=n,i="next"===r;ba(n)?e.set(o.getKey(),n.offset,"text"):wa(n)?Xo(o)?e.set(o.getKey(),Fa(o,r),"text"):e.set(o.getParentOrThrow().getKey(),o.getIndexWithinParent()+(i?1:0),"element"):(Ea(n)&&Pi(o)||t(268),e.set(o.getKey(),i?0:o.getChildrenSize(),"element"));}function Za(t){const e=Lr(),n=cr(e)?e:Dr();return tu(n,t),el(n),n}function tu(t,e){Qa(t.anchor,e.anchor),Qa(t.focus,e.focus);}function eu(t){const{anchor:e,focus:n}=t,o=Xa(e,"next"),r=Xa(n,"next"),i=ja(o,r)<=0?"next":"previous";return Ha(cu(o,i),cu(r,i))}function nu(t){const{direction:e,origin:n}=t,o=Da(n,Sa(e)).getNodeAtCaret();return o?Da(o,e):La(n.getParentOrThrow(),e)}function ou(t,e="root"){const n=[t];for(let o=Ea(t)?t.getParentCaret(e):t.getSiblingCaret();null!==o;o=o.getParentCaret(e))n.push(nu(o));return n}function ru(t){return !!t&&t.origin.isAttached()}function iu(e,n="removeEmptySlices"){if(e.isCollapsed())return e;const o="root",r="next";let i=n;const s=au(e,r),l=ou(s.anchor,o),c=ou(s.focus.getFlipped(),o),a=new Set,u=[];for(const t of s.iterNodeCarets(o))if(Ea(t))a.add(t.origin.getKey());else if(wa(t)){const{origin:e}=t;Pi(e)&&!a.has(e.getKey())||u.push(e);}for(const t of u)t.remove();for(const t of s.getTextSlices()){if(!t)continue;const{origin:e}=t.caret,n=e.getTextContentSize(),o=nu(Da(e,r)),s=e.getMode();if(Math.abs(t.distance)===n&&"removeEmptySlices"===i||"token"===s&&0!==t.distance)o.remove();else if(0!==t.distance){i="removeEmptySlices";let e=t.removeTextSlice();const n=t.caret.origin;if("segmented"===s){const t=e.origin,n=Go(t.getTextContent()).setStyle(t.getStyle()).setFormat(t.getFormat());o.replaceOrInsert(n),e=Pa(n,r,e.offset);}n.is(l[0].origin)&&(l[0]=e),n.is(c[0].origin)&&(c[0]=e.getFlipped());}}let f,d;for(const t of l)if(ru(t)){f=su(t);break}for(const t of c)if(ru(t)){d=su(t);break}const h=function(t,e,n){if(!t||!e)return null;const o=t.getParentAtCaret(),r=e.getParentAtCaret();if(!o||!r)return null;const i=o.getParents().reverse();i.push(o);const s=r.getParents().reverse();s.push(r);const l=Math.min(i.length,s.length);let c;for(c=0;c<l&&i[c]===s[c];c++);const a=(t,e)=>{let n;for(let o=c;o<t.length;o++){const r=t[o];if(Kl(r))return;!n&&e(r)&&(n=r);}return n},u=a(i,xc),f=u&&a(s,t=>n.has(t.getKey())&&xc(t));if(f&&na(f).length>0)return null;return u&&f?[u,f]:null}(f,d,a);if(h){const[t,e]=h;La(t,"previous").splice(0,e.getChildren());let n=e.getParent();for(e.remove(true);n&&n.isEmpty();){const t=n;n=n.getParent(),t.remove(true);}}else if(d){const t=function(t){if(Ea(t)){const e=t.origin;if(xc(e))return e}else {const e=t.getParentAtCaret();if(e&&xc(e))return e}return null}(d),e=t&&t.getParent(),n=t&&t.getParents().findLast(Ll);if(t&&e&&!zi(e)&&t.isEmpty()&&a.has(t.getKey())&&0===na(t).length&&(!n||a.has(n.getKey()))){t.remove(true);let n=e;for(;n&&!zi(n)&&n.isEmpty();){const t=n.getParent();if(t&&zi(t)&&t.getChildrenSize()<=1)break;const e=n;n=t,e.remove(true);}}}const g=[f,d,...l,...c].find(ru);if(g){return $a(cu(su(g),e.direction))}t(269,JSON.stringify(l.map(t=>t.origin.__key)));}function su(t){const e=function(t){let e=t;for(;Ea(e);){const t=za(e);if(!Ea(t))break;e=t;}return e}(t.getLatest()),{direction:n}=e;if(Xo(e.origin))return ba(e)?e:Pa(e.origin,n,n);const o=e.getAdjacentCaret();return wa(o)&&Xo(o.origin)?Pa(o.origin,n,Sa(n)):e}function lu(t){return ba(t)&&t.offset!==Fa(t.origin,t.direction)}function cu(t,e){return t.direction===e?t:t.getFlipped()}function au(t,e){return t.direction===e?t:Ha(cu(t.focus,e),cu(t.anchor,e))}function uu(t,e,n){let o=La(t,"next");for(let t=0;t<e;t++){const t=o.getAdjacentCaret();if(null===t)break;o=t;}return cu(o,n)}function fu(t,e="root"){let n=0,o=t,r=za(o);for(;null===r;){if(n--,r=o.getParentCaret(e),!r)return null;o=r,r=za(o);}return r&&[r,n]}function du(e){const{origin:n,offset:o,direction:r}=e;if(o===Fa(n,r))return e.getSiblingCaret();if(o===Fa(n,Sa(r)))return nu(e.getSiblingCaret());const[i]=n.splitText(o);return Xo(i)||t(281),cu(Da(i,"next"),r)}function hu(t,e){return  true}function gu(t,{$copyElementNode:e=zl,$splitTextPointCaretNext:n=du,rootMode:o="shadowRoot",$shouldSplit:r=hu,removeEmptyDestination:i=false}={}){if(ba(t))return n(t);const s=t.getParentCaret(o);if(s){const{origin:n}=s;if(Ea(t)){const t=nu(s);if(i&&n.isEmpty())return n.remove(),t;if(!n.canBeEmpty()||!r(n,"first"))return t}const o=function(t){const e=[];for(let n=t.getAdjacentCaret();n;n=n.getAdjacentCaret())e.push(n.origin);return e}(t);(o.length>0||!i&&n.canBeEmpty()&&r(n,"last"))&&s.insert(e(n).splice(0,0,o));}return s}function _u(e,n,o){let r=cu(n,"next");ba(r)&&(0===r.offset?r=Da(r.origin,"previous").getFlipped():r.offset===r.origin.getTextContentSize()&&(r=Da(r.origin,"next"))),r.origin.is(e)&&(wa(r)||t(342,e.getKey(),e.getType()),r=nu(r)),(e.is(r.getNodeAtCaret())||e.is(r.getFlipped().getNodeAtCaret()))&&e.remove(true);for(let t=r;t;t=gu(t,o))r=t;return ba(r)&&t(283),r.insert(e.isInline()?ts().append(e):e),cu(Da(e.getLatest(),"next"),n.direction)}function pu(t){return t}function xu(t){return t}function Cu(t,e){if(!e||t===e)return t;for(const n in e)if(t[n]!==e[n])return {...t,...e};return t}function Su(...t){const e=[];for(const n of t)if(n&&"string"==typeof n)for(const[t]of n.matchAll(/\S+/g))e.push(t);return e}function vu(t,...e){const n=Su(...e);n.length>0&&t.classList.add(...n);}function Tu(t,...e){const n=Su(...e);n.length>0&&t.classList.remove(...n);}function ku(...t){return ()=>{for(let e=t.length-1;e>=0;e--)t[e]();t.length=0;}}

	/**
	 * Copyright (c) Meta Platforms, Inc. and affiliates.
	 *
	 * This source code is licensed under the MIT license found in the
	 * LICENSE file in the root directory of this source tree.
	 *
	 */

	function R(e){const t=Cc().getElementByKey(e.getKey());if(null===t)return null;const o=t.ownerDocument.defaultView;return null===o?null:o.getComputedStyle(t)}function O$1(e){return R(zi(e)?e:e.getParentOrThrow())}function _(e){const t=O$1(e);return null!==t&&"rtl"===t.direction}function L(e,t,n="self"){const o=e.getStartEndPoints();if(t.isSelected(e)&&!Is(t)&&null!==o){const[l,r]=o,i=e.isBackward(),s=l.getNode(),c=r.getNode(),g=t.is(s),a=t.is(c);if(g||a){const[o,l]=_r(e),r=s.is(c),g=t.is(i?c:s),a=t.is(i?s:c);let d,p=0;if(r)p=o>l?l:o,d=o>l?o:l;else if(g){p=i?l:o,d=void 0;}else if(a){p=0,d=i?o:l;}const h=t.__text.slice(p,d);h!==t.__text&&("clone"===n&&(t=Ac(t)),t.__text=h);}}return t}function Z$3(e){const t=ee$3(e);return null!==t&&"vertical-rl"===t.writingMode}function ee$3(e){const t=e.anchor.getNode();return Pi(t)?R(t):O$1(t)}function te$3(e,n){let o=Z$3(e)?!n:n;oe$2(e)&&(o=!o);const l=Xa(e.focus,o?"previous":"next");if(lu(l))return  false;if(ba(l)&&!er(l.origin)&&l.origin.isUnmergeable()){const e=l.getNodeAtCaret();if(Xo(e)&&!er(e))return  true}for(const e of Ua(l)){if(Ea(e))return !e.origin.isInline();if(!Pi(e.origin)){if(Li(e.origin))return  true;break}}return  false}function ne$2(e,t,n,o){e.modify(t?"extend":"move",n,o);}function oe$2(e){const t=ee$3(e);return null!==t&&"rtl"===t.direction}function le$3(e,t,n){const o=oe$2(e);let l;l=Z$3(e)||o?!n:n,ne$2(e,t,l,"character");}

	/**
	 * Copyright (c) Meta Platforms, Inc. and affiliates.
	 *
	 * This source code is licensed under the MIT license found in the
	 * LICENSE file in the root directory of this source tree.
	 *
	 */

	function Z$2(t,...e){const n=new URL("https://lexical.dev/docs/error"),o=new URLSearchParams;o.append("code",t);for(const t of e)o.append("v",t);throw n.search=o.toString(),Error(`Minified Lexical error #${t}; visit ${n.toString()} for the full message or use the non-minified dev environment for full errors and additional helpful warnings.`)}function dt$6(t,e){return Array.from(pt$5(t))}function pt$5(t,e){return Et$5("next",t)}function vt$4(t,e){const n=fu(Da(t,e));return n&&n[0]}function Et$5(t,e,n){const o=tl(),r=e||o,i=Pi(r)?La(r,t):Da(r,t),l=Ct$5(r),u=vt$4(r,t);let c=l;return Ja({hasNext:t=>null!==t,initial:i,map:t=>({depth:c,node:t.origin}),step:t=>{if(t.isSameNodeCaret(u))return null;Ea(t)&&c++;const e=fu(t);return !e||e[0].isSameNodeCaret(u)?null:(c+=e[1],e[0])}})}function Ct$5(t){let e=-1;for(let n=t;null!==n;n=n.getParent()??Qc(n))e++;return e}function _t$3(t,e){let n=t;for(;null!=n;){if(n instanceof e)return n;n=n.getParent();}return null}function It$4(t){const e=Jc(t,t=>Pi(t)&&!t.isInline());return Pi(e)||Z$2(4,t.__key),e}function Bt$3(t){const e=Lr()||Kr();let n;if(cr(e))n=Xa(e.focus,"next");else {if(null!=e){const t=e.getNodes(),o=t[t.length-1];o&&(n=Da(o,"next"));}n=n||La(tl(),"previous").getFlipped().insert(ts());}const o=_u(t,n),r=za(o),s=Ea(r)?su(r):o;return Za($a(s)),t.getLatest()}function Mt$4(t,e){return null!==t&&Object.getPrototypeOf(t).constructor.name===e.name}function Ft$4(t){let e=null;if(Mt$4(t,DragEvent)?e=t.dataTransfer:Mt$4(t,ClipboardEvent)&&(e=t.clipboardData),null===e)return [false,[],false];const n=e.types,o=n.includes("Files"),r=n.includes("text/html")||n.includes("text/plain");return [o,Array.from(e.files),r]}function $t$6(t){const e=Lr();if(!cr(e))return  false;const n=new Set,o=e.getNodes();for(let e=0;e<o.length;e++){const r=o[e],i=r.getKey();if(n.has(i))continue;const l=Jc(r,t=>Pi(t)&&!t.isInline());if(null===l)continue;const u=l.getKey();l.canIndent()&&!n.has(u)&&(n.add(u),t(l));}return n.size>0}function zt$5(t,e){return Vt$3(t,e,null)}function Vt$3(t,e,n){let o=false;for(const r of Gt$1(t))e(r)?null!==n&&n(r):(o=true,Pi(r)&&Vt$3(r,e,n||(t=>r.insertAfter(t))),r.remove());return o}function Wt$3(t,e){const n=[],o=Array.from(t).reverse();for(let t=o.pop();void 0!==t;t=o.pop())if(e(t))n.push(t);else if(Pi(t))for(const e of Gt$1(t))o.push(e);return n}function Gt$1(t){return Yt$2(La(t,"previous"))}function Yt$2(t){return Ja({hasNext:wa,initial:t.getAdjacentCaret(),map:t=>t.origin.getLatest(),step:t=>t.getAdjacentCaret()})}

	/**
	 * Copyright (c) Meta Platforms, Inc. and affiliates.
	 *
	 * This source code is licensed under the MIT license found in the
	 * LICENSE file in the root directory of this source tree.
	 *
	 */

	const Kt$3=Symbol.for("preact-signals");function $t$5(){if(Bt$2>1)return void Bt$2--;let t,e=false;for(!function(){let t=Wt$2;for(Wt$2=void 0;void 0!==t;)t.S.v===t.v&&(t.S.i=t.i),t=t.o;}();void 0!==zt$4;){let n=zt$4;for(zt$4=void 0,Vt$2++;void 0!==n;){const i=n.u;if(n.u=void 0,n.f&=-3,!(8&n.f)&&qt$3(n))try{n.c();}catch(n){e||(t=n,e=true);}n=i;}}if(Vt$2=0,Bt$2--,e)throw t}let jt$3,zt$4;function Ut$2(t){const e=jt$3;jt$3=void 0;try{return t()}finally{jt$3=e;}}let Wt$2,Bt$2=0,Vt$2=0,Zt$3=0,Jt$1=0;function Ht$3(t){if(void 0===jt$3)return;let e=t.n;return void 0===e||e.t!==jt$3?(e={i:0,S:t,p:jt$3.s,n:void 0,t:jt$3,e:void 0,x:void 0,r:e},void 0!==jt$3.s&&(jt$3.s.n=e),jt$3.s=e,t.n=e,32&jt$3.f&&t.S(e),e):-1===e.i?(e.i=0,void 0!==e.n&&(e.n.p=e.p,void 0!==e.p&&(e.p.n=e.n),e.p=jt$3.s,e.n=void 0,jt$3.s.n=e,jt$3.s=e),e):void 0}function Xt$3(t,e){this.v=t,this.i=0,this.n=void 0,this.t=void 0,this.l=0,this.W=null==e?void 0:e.watched,this.Z=null==e?void 0:e.unwatched,this.name=null==e?void 0:e.name;}function Yt$1(t,e){return new Xt$3(t,e)}function qt$3(t){for(let e=t.s;void 0!==e;e=e.n)if(e.S.i!==e.i||!e.S.h()||e.S.i!==e.i)return  true;return  false}function Qt$1(t){for(let e=t.s;void 0!==e;e=e.n){const n=e.S.n;if(void 0!==n&&(e.r=n),e.S.n=e,e.i=-1,void 0===e.n){t.s=e;break}}}function te$2(t){let e,n=t.s;for(;void 0!==n;){const t=n.p;-1===n.i?(n.S.U(n),void 0!==t&&(t.n=n.n),void 0!==n.n&&(n.n.p=t)):e=n,n.S.n=n.r,void 0!==n.r&&(n.r=void 0),n=t;}t.s=e;}function ee$2(t,e){Xt$3.call(this,void 0),this.x=t,this.s=void 0,this.g=Jt$1-1,this.f=4,this.W=null==e?void 0:e.watched,this.Z=null==e?void 0:e.unwatched,this.name=null==e?void 0:e.name;}function ie$2(t){const e=t.m;if(t.m=void 0,"function"==typeof e){Bt$2++;const n=jt$3;jt$3=void 0;try{e();}catch(e){throw t.f&=-2,t.f|=8,oe$1(t),e}finally{jt$3=n,$t$5();}}}function oe$1(t){for(let e=t.s;void 0!==e;e=e.n)e.S.U(e);t.x=void 0,t.s=void 0,ie$2(t);}function se$2(t){if(jt$3!==this)throw new Error("Out-of-order effect");te$2(this),jt$3=t,this.f&=-2,8&this.f&&oe$1(this),$t$5();}function re$3(t,e){this.x=t,this.m=void 0,this.s=void 0,this.u=void 0,this.f=32,this.name=null==e?void 0:e.name;}function ce$2(t,e){const n=new re$3(t,e);try{n.c();}catch(t){throw n.d(),t}const i=n.d.bind(n);return i[Symbol.dispose]=i,i}Xt$3.prototype.brand=Kt$3,Xt$3.prototype.h=function(){return  true},Xt$3.prototype.S=function(t){const e=this.t;e!==t&&void 0===t.e&&(t.x=e,this.t=t,void 0!==e?e.e=t:Ut$2(()=>{var t;null==(t=this.W)||t.call(this);}));},Xt$3.prototype.U=function(t){if(void 0!==this.t){const e=t.e,n=t.x;void 0!==e&&(e.x=n,t.e=void 0),void 0!==n&&(n.e=e,t.x=void 0),t===this.t&&(this.t=n,void 0===n&&Ut$2(()=>{var t;null==(t=this.Z)||t.call(this);}));}},Xt$3.prototype.subscribe=function(t){return ce$2(()=>{const e=this.value,n=jt$3;jt$3=void 0;try{t(e);}finally{jt$3=n;}},{name:"sub"})},Xt$3.prototype.valueOf=function(){return this.value},Xt$3.prototype.toString=function(){return this.value+""},Xt$3.prototype.toJSON=function(){return this.value},Xt$3.prototype.peek=function(){const t=jt$3;jt$3=void 0;try{return this.value}finally{jt$3=t;}},Object.defineProperty(Xt$3.prototype,"value",{get(){const t=Ht$3(this);return void 0!==t&&(t.i=this.i),this.v},set(t){if(t!==this.v){if(Vt$2>100)throw new Error("Cycle detected");!function(t){0!==Bt$2&&0===Vt$2&&t.l!==Zt$3&&(t.l=Zt$3,Wt$2={S:t,v:t.v,i:t.i,o:Wt$2});}(this),this.v=t,this.i++,Jt$1++,Bt$2++;try{for(let t=this.t;void 0!==t;t=t.x)t.t.N();}finally{$t$5();}}}}),ee$2.prototype=new Xt$3,ee$2.prototype.h=function(){if(this.f&=-3,1&this.f)return  false;if(32==(36&this.f))return  true;if(this.f&=-5,this.g===Jt$1)return  true;if(this.g=Jt$1,this.f|=1,this.i>0&&!qt$3(this))return this.f&=-2,true;const t=jt$3;try{Qt$1(this),jt$3=this;const t=this.x();(16&this.f||this.v!==t||0===this.i)&&(this.v=t,this.f&=-17,this.i++);}catch(t){this.v=t,this.f|=16,this.i++;}return jt$3=t,te$2(this),this.f&=-2,true},ee$2.prototype.S=function(t){if(void 0===this.t){this.f|=36;for(let t=this.s;void 0!==t;t=t.n)t.S.S(t);}Xt$3.prototype.S.call(this,t);},ee$2.prototype.U=function(t){if(void 0!==this.t&&(Xt$3.prototype.U.call(this,t),void 0===this.t)){this.f&=-33;for(let t=this.s;void 0!==t;t=t.n)t.S.U(t);}},ee$2.prototype.N=function(){if(!(2&this.f)){this.f|=6;for(let t=this.t;void 0!==t;t=t.x)t.t.N();}},Object.defineProperty(ee$2.prototype,"value",{get(){if(1&this.f)throw new Error("Cycle detected");const t=Ht$3(this);if(this.h(),void 0!==t&&(t.i=this.i),16&this.f)throw this.v;return this.v}}),re$3.prototype.c=function(){const t=this.S();try{if(8&this.f)return;if(void 0===this.x)return;const t=this.x();"function"==typeof t&&(this.m=t);}finally{t();}},re$3.prototype.S=function(){if(1&this.f)throw new Error("Cycle detected");this.f|=1,this.f&=-9,ie$2(this),Qt$1(this),Bt$2++;const t=jt$3;return jt$3=this,se$2.bind(this,t)},re$3.prototype.N=function(){2&this.f||(this.f|=2,this.u=zt$4,zt$4=this);},re$3.prototype.d=function(){this.f|=8,1&this.f||oe$1(this);},re$3.prototype.dispose=function(){this.d();};function ve$1(t){return ("function"==typeof t.nodes?t.nodes():t.nodes)||[]}function Ie$1(t,...e){const n=new URL("https://lexical.dev/docs/error"),i=new URLSearchParams;i.append("code",t);for(const t of e)i.append("v",t);throw n.search=i.toString(),Error(`Minified Lexical error #${t}; visit ${n.toString()} for the full message or use the non-minified dev environment for full errors and additional helpful warnings.`)}let Fe$2;try{Fe$2="0.48.0+prod.esm";}catch(t){}const Me$1=Fe$2??'"<unknown>+source"',_e$1=new Set(["__proto__","constructor","prototype"]);function ke$2(t,e){if(t&&e&&!Array.isArray(e)&&"object"==typeof t&&"object"==typeof e){const n=t,i=e;for(const t in i)!_e$1.has(t)&&Object.prototype.hasOwnProperty.call(i,t)&&(n[t]=ke$2(n[t],i[t]));return t}return e}const Ae$1=0,Pe$2=1,Le$2=2,Ke$2=3,$e$2=4,Te$3=5,je$1=6,ze$1=7;function Ue$1(t){return t.id===Ae$1}function We$1(t){return t.id===Le$2}function Be$2(t){return function(t){return t.id===Pe$2}(t)||Ie$1(305,String(t.id),String(Pe$2)),Object.assign(t,{id:Le$2})}const Ve$1=new Set;let Ge$1 = class Ge{builder;configs;_dependency;_peerNameSet;extension;state;_signal;constructor(t,e){this.builder=t,this.extension=e,this.configs=new Set,this.state={id:Ae$1};}mergeConfigs(){let t=this.extension.config||{};const e=this.extension.mergeConfig?this.extension.mergeConfig.bind(this.extension):Cu;for(const n of this.configs)t=e(t,n);return t}init(t){const e=this.state;We$1(e)||Ie$1(306,String(e.id));const n={getDependency:this.getInitDependency.bind(this),getDirectDependentNames:this.getDirectDependentNames.bind(this),getPeer:this.getInitPeer.bind(this),getPeerNameSet:this.getPeerNameSet.bind(this)},i={...n,getDependency:this.getDependency.bind(this),getInitResult:this.getInitResult.bind(this),getPeer:this.getPeer.bind(this)},o=function(t,e,n){return Object.assign(t,{config:e,id:Ke$2,registerState:n})}(e,this.mergeConfigs(),n);let s;this.state=o,this.extension.init&&(s=this.extension.init(t,o.config,n)),this.state=function(t,e,n){return Object.assign(t,{id:$e$2,initResult:e,registerState:n})}(o,s,i);}build(t){const e=this.state;let n;e.id!==$e$2&&Ie$1(307,String(e.id),String(Te$3)),this.extension.build&&(n=this.extension.build(t,e.config,e.registerState));const i={...e.registerState,getOutput:()=>n,getSignal:this.getSignal.bind(this)};this.state=function(t,e,n){return Object.assign(t,{id:Te$3,output:e,registerState:n})}(e,n,i);}register(t,e){this._signal=e;const n=this.state;n.id!==Te$3&&Ie$1(308,String(n.id),String(Te$3));const i=this.extension.register&&this.extension.register(t,n.config,n.registerState);return this.state=function(t){return Object.assign(t,{id:je$1})}(n),()=>{const t=this.state;t.id!==ze$1&&Ie$1(309,String(n.id),String(ze$1)),this.state=function(t){return Object.assign(t,{id:Te$3})}(t),i&&i();}}afterRegistration(t){const e=this.state;let n;return e.id!==je$1&&Ie$1(310,String(e.id),String(je$1)),this.extension.afterRegistration&&(n=this.extension.afterRegistration(t,e.config,e.registerState)),this.state=function(t){return Object.assign(t,{id:ze$1})}(e),n}getSignal(){return void 0===this._signal&&Ie$1(311),this._signal}getInitResult(){ void 0===this.extension.init&&Ie$1(312,this.extension.name);const t=this.state;return function(t){return t.id>=$e$2}(t)||Ie$1(313,String(t.id),String($e$2)),t.initResult}getInitPeer(t){const e=this.builder.extensionNameMap.get(t);return e?e.getExtensionInitDependency():void 0}getExtensionInitDependency(){const t=this.state;return function(t){return t.id>=Ke$2}(t)||Ie$1(314,String(t.id),String(Ke$2)),{config:t.config}}getPeer(t){const e=this.builder.extensionNameMap.get(t);return e?e.getExtensionDependency():void 0}getInitDependency(t){const e=this.builder.getExtensionRep(t);return void 0===e&&Ie$1(315,this.extension.name,t.name),e.getExtensionInitDependency()}getDependency(t){const e=this.builder.getExtensionRep(t);return void 0===e&&Ie$1(315,this.extension.name,t.name),e.getExtensionDependency()}getState(){const t=this.state;return function(t){return t.id>=ze$1}(t)||Ie$1(316,String(t.id),String(ze$1)),t}getDirectDependentNames(){return this.builder.incomingEdges.get(this.extension.name)||Ve$1}getPeerNameSet(){let t=this._peerNameSet;return t||(t=new Set((this.extension.peerDependencies||[]).map(([t])=>t)),this._peerNameSet=t),t}getExtensionDependency(){if(!this._dependency){const t=this.state;((function(t){return t.id>=Te$3}))(t)||Ie$1(317,this.extension.name),this._dependency={config:t.config,init:t.initResult,output:t.output};}return this._dependency}};const Ze$1={tag:xo};function Je$2(){const t=tl();t.isEmpty()&&t.append(ts());}const He$2=/* @__PURE__ */pu({config:/* @__PURE__ */xu({setOptions:Ze$1,updateOptions:Ze$1}),init:({$initialEditorState:t=Je$2})=>({$initialEditorState:t,initialized:false}),afterRegistration(t,{updateOptions:e,setOptions:n},i){const o=i.getInitResult();if(!o.initialized){o.initialized=true;const{$initialEditorState:i}=o;if(Ui(i))t.setEditorState(i,n);else if("function"==typeof i)t.update(()=>{i(t);},e);else if(i&&("string"==typeof i||"object"==typeof i)){const e=t.parseEditorState(i);t.setEditorState(e,n);}}return ()=>{}},name:"@lexical/extension/InitialState",nodes:[Ki,Wo,Ji,Zo,Qi]}),Xe$1=Symbol.for("@lexical/extension/LexicalBuilder");function qe$2(){}function Qe$1(t){throw t}function tn$2(t){return Array.isArray(t)?t:[t]}const en$2=Me$1;let nn$1 = class nn{roots;extensionNameMap;outgoingConfigEdges;incomingEdges;conflicts;_sortedExtensionReps;PACKAGE_VERSION;constructor(t){this.outgoingConfigEdges=new Map,this.incomingEdges=new Map,this.extensionNameMap=new Map,this.conflicts=new Map,this.PACKAGE_VERSION=en$2,this.roots=t;for(const e of t)this.addExtension(e);}static fromExtensions(t){const e=[tn$2(He$2)];for(const n of t)e.push(tn$2(n));return new nn(e)}static maybeFromEditor(t){const e=t[Xe$1];return e&&(e.PACKAGE_VERSION!==en$2&&Ie$1(292,e.PACKAGE_VERSION,en$2),e instanceof nn||Ie$1(293)),e}static fromEditor(t){const e=nn.maybeFromEditor(t);return void 0===e&&Ie$1(294),e}constructEditor(){const{$initialEditorState:t,onError:e,onWarn:n,...i}=this.buildCreateEditorArgs(),o=Object.assign(ps({...i,...e?{onError:t=>{e(t,o);}}:{},...n?{onWarn:t=>{n(t,o);}}:{}}),{[Xe$1]:this});for(const t of this.sortedExtensionReps())t.build(o);return o}buildEditor(){let t=qe$2;function e(){try{t();}finally{t=qe$2;}}const n=Object.assign(this.constructEditor(),{dispose:e,[Symbol.dispose]:e});return t=ku(this.registerEditor(n),()=>n.setRootElement(null)),n}hasExtensionByName(t){return this.extensionNameMap.has(t)}getExtensionRep(t){const e=this.extensionNameMap.get(t.name);if(e)return e.extension!==t&&Ie$1(295,t.name),e}addEdge(t,e,n){const i=this.outgoingConfigEdges.get(t);i?i.set(e,n):this.outgoingConfigEdges.set(t,new Map([[e,n]]));const o=this.incomingEdges.get(e);o?o.add(t):this.incomingEdges.set(e,new Set([t]));}addExtension(t){ void 0!==this._sortedExtensionReps&&Ie$1(296);const e=tn$2(t),[n]=e;"string"!=typeof n.name&&Ie$1(297,typeof n.name);let i=this.extensionNameMap.get(n.name);if(void 0!==i&&i.extension!==n&&Ie$1(298,n.name),!i){i=new Ge$1(this,n),this.extensionNameMap.set(n.name,i);const t=this.conflicts.get(n.name);"string"==typeof t&&Ie$1(299,n.name,t);for(const t of n.conflictsWith||[])this.extensionNameMap.has(t)&&Ie$1(299,n.name,t),this.conflicts.set(t,n.name);for(const t of n.dependencies||[]){const e=tn$2(t);this.addEdge(n.name,e[0].name,e.slice(1)),this.addExtension(e);}for(const[t,e]of n.peerDependencies||[])this.addEdge(n.name,t,e?[e]:[]);}}sortedExtensionReps(){if(this._sortedExtensionReps)return this._sortedExtensionReps;const t=[],e=(n,i)=>{let o=n.state;if(We$1(o))return;const s=n.extension.name;var r;Ue$1(o)||Ie$1(300,s,i||"[unknown]"),Ue$1(r=o)||Ie$1(304,String(r.id),String(Ae$1)),o=Object.assign(r,{id:Pe$2}),n.state=o;const c=this.outgoingConfigEdges.get(s);if(c)for(const t of c.keys()){const n=this.extensionNameMap.get(t);n&&e(n,s);}o=Be$2(o),n.state=o,t.push(n);};for(const t of this.extensionNameMap.values())Ue$1(t.state)&&e(t);for(const e of t)for(const[t,n]of this.outgoingConfigEdges.get(e.extension.name)||[])if(n.length>0){const e=this.extensionNameMap.get(t);if(e)for(const t of n)e.configs.add(t);}for(const[t,...e]of this.roots)if(e.length>0){const n=this.extensionNameMap.get(t.name);void 0===n&&Ie$1(301,t.name);for(const t of e)n.configs.add(t);}return this._sortedExtensionReps=t,this._sortedExtensionReps}registerEditor(t){const e=this.sortedExtensionReps(),n=new AbortController,i=[()=>n.abort()],o=n.signal;for(const n of e){const e=n.register(t,o);e&&i.push(e);}for(const n of e){const e=n.afterRegistration(t);e&&i.push(e);}return ku(...i)}buildCreateEditorArgs(){const t={},e=new Set,n=new Map,i=new Map,o={},s={},r=this.sortedExtensionReps();for(const c of r){const{extension:r}=c;if(void 0!==r.onError&&(t.onError=r.onError),void 0!==r.onWarn&&(t.onWarn=r.onWarn),void 0!==r.disableEvents&&(t.disableEvents=r.disableEvents),void 0!==r.parentEditor&&(t.parentEditor=r.parentEditor),void 0!==r.editable&&(t.editable=r.editable),void 0!==r.namespace&&(t.namespace=r.namespace),void 0!==r.$initialEditorState&&(t.$initialEditorState=r.$initialEditorState),r.nodes)for(const t of ve$1(r)){if("function"!=typeof t){const e=n.get(t.replace);e&&Ie$1(302,r.name,t.replace.name,e.extension.name),n.set(t.replace,c);}e.add(t);}if(r.html){if(r.html.export)for(const[t,e]of r.html.export.entries())i.set(t,e);r.html.import&&Object.assign(o,r.html.import);}r.theme&&ke$2(s,r.theme);}Object.keys(s).length>0&&(t.theme=s),e.size&&(t.nodes=[...e]);const c=Object.keys(o).length>0,a=i.size>0;(c||a)&&(t.html={},c&&(t.html.import=o),a&&(t.html.export=i));for(const e of r)e.init(t);return t.onError||(t.onError=Qe$1),t}};function sn$1(t,e){const n=nn$1.maybeFromEditor(t);if(!n)return;const i=n.extensionNameMap.get(e);return i?i.getExtensionDependency():void 0}function dn$1(t){return sn$1(Cc(),t)}let hn$1 = class hn extends Ii{static getType(){return "horizontalrule"}static clone(t){return new hn(t.__key)}static importJSON(t){return pn$1().updateFromJSON(t)}static importDOM(){return {hr:()=>({conversion:gn$1,priority:0})}}exportDOM(){return {element:Xl().createElement("hr")}}createDOM(t){const e=Xl().createElement("hr");return vu(e,t.theme.hr),e}getTextContent(){return "\n"}isInline(){return  false}updateDOM(){return  false}};function gn$1(){return {node:pn$1()}}function pn$1(){return Hc(hn$1)}

	/**
	 * Copyright (c) Meta Platforms, Inc. and affiliates.
	 *
	 * This source code is licensed under the MIT license found in the
	 * LICENSE file in the root directory of this source tree.
	 *
	 */

	function nt$4(t,...e){const n=new URL("https://lexical.dev/docs/error"),o=new URLSearchParams;o.append("code",t);for(const t of e)o.append("v",t);throw n.search=o.toString(),Error(`Minified Lexical error #${t}; visit ${n.toString()} for the full message or use the non-minified dev environment for full errors and additional helpful warnings.`)}let ot$3;function rt$4(t,e){const{key:n}=e;return t&&n in t?t[n]:e.defaultValue}function st$4(t){return ot$3&&ot$3.editor===t?ot$3:void 0}function ct$3(t,e){if("cfg"in e){const{cfg:n,updater:o}=e;return [n,o(rt$4(t,n))]}return e}function lt$4(t,e){let n=e;for(const o of t){const[t,r]=ct$3(n,o),s=t.key;if(n===e&&rt$4(n,t)===r)continue;const i=n===e||void 0===n?ut$4(e):n;i[s]=r,n=i;}return n}function ut$4(t){return Object.create(t||null)}function ft$3(t,e){return [t,e]}function dt$5(t,n,o,r=Cc()){const s=ot$3,i=st$4(r);try{return ot$3={...i,editor:r,[t]:n},o()}finally{ot$3=s;}}function pt$4(t,n=()=>{}){return (o,r=Cc())=>e=>{const s=st$4(r),i=s&&s[t],c=lt$4(o,i||n(r));return c&&c!==i?dt$5(t,c,e,r):e()}}function ht$4(t,e,o,r){return Object.assign(mt$3(Symbol(e),{isEqual:r,parse:o}),{[t]:true})}function mt$2(t){if(!Ks(t))return;const e=t;if(null===e.querySelector("style"))return;const n=new Map;function s(t){let e=n.get(t);if(void 0===e){e=new Set;for(let n=0;n<t.style.length;n++)e.add(t.style[n]);n.set(t,e);}return e}try{for(const t of Array.from(e.styleSheets)){let n;try{n=t.cssRules;}catch(t){continue}for(const t of Array.from(n)){if(!Mt$4(t,CSSStyleRule))continue;let n;try{n=e.querySelectorAll(t.selectorText);}catch(t){continue}for(const e of Array.from(n)){if(!dc(e))continue;const n=s(e);for(let o=0;o<t.style.length;o++){const r=t.style[o];n.has(r)||e.style.setProperty(r,t.style.getPropertyValue(r),t.style.getPropertyPriority(r));}}}}}catch(t){}}const yt$3="@lexical/html/DOM",xt$3=Symbol.for("@lexical/html/DOMExportContext"),$t$4=Symbol.for("@lexical/html/DOMImportContext");function Ct$4(t,e,n){return ht$4(xt$3,t,e,n)}const kt$3=/* @__PURE__ */Ct$4("isExport",Boolean);function Dt$5(t){const e=sn$1(t,yt$3);return e?e.output.defaults:void 0}function Ot$4(t){const e=sn$1(t,yt$3);return e?e.output.runtime:void 0}function Nt$4(t=Cc()){const n=Ot$4(t);return n?n.getSessionConfig():Sc(t)}const Et$4=pt$4(xt$3,Dt$5);const re$2=Symbol.for("@lexical/html/SelectorImpl");function se$1(t,e){const n={kind:"element",predicate:(o=e,0===o.length?dc:1===o.length?o[0]:(t,e)=>{for(const n of o)if(!n(t,e))return  false;return  true}),tags:t};var o;const s=n=>se$1(t,[...e,n]);return {[re$2]:n,attr:(t,e,n)=>s(le$2(t,e,n)),classAll:(...t)=>s(ce$1(t)),classAny:(...t)=>s(function(t){const e=ie$1(t);if(0===e.length)return ()=>false;return t=>{if(!dc(t))return  false;const n=t.classList;for(const t of e)if(n.contains(t))return  true;return  false}}(t)),styleAny:(t,e,n)=>s(function(t,e,n){if("string"==typeof e)return n=>dc(n)&&n.style.getPropertyValue(t)===e;if(e instanceof RegExp){const o=n&&n.capture,s=e;return (e,n)=>{if(!dc(e))return  false;const i=e.style.getPropertyValue(t);if(!i)return  false;const c=i.match(s);return null!==c&&(void 0!==o&&(n[o]=c),true)}}nt$4(362,JSON.stringify(t));}(t,e,n))}}function ie$1(t){const e=[];for(const n of t)n&&e.push(n);return e}function ce$1(t){const e=ie$1(t);return 0===e.length?()=>true:t=>{if(!dc(t))return  false;const n=t.classList;for(const t of e)if(!n.contains(t))return  false;return  true}}function le$2(t,e,n){if(true===e)return e=>dc(e)&&e.hasAttribute(t);if("string"==typeof e)return n=>dc(n)&&n.getAttribute(t)===e;if(e instanceof RegExp){const o=n&&n.capture,s=e;return (e,n)=>{if(!dc(e))return  false;const i=e.getAttribute(t);if(null==i)return  false;const c=i.match(s);return null!==c&&(void 0!==o&&(n[o]=c),true)}}nt$4(361,JSON.stringify(t));}const ue$2={kind:"text",predicate:Ls,tags:new Set},fe$1={[re$2]:ue$2},ae$1={kind:"comment",predicate:t=>8===t.nodeType,tags:new Set},de$1={[re$2]:ae$1},pe$1={any:()=>se$1(new Set,[]),comment:()=>de$1,tag(...t){t.length>0||nt$4(363);const e=new Set;for(const n of t)e.add(n.toUpperCase());return se$1(e,[])},text:()=>fe$1};function he$1(t,e){return dc(t)&&t.nodeName===e.toUpperCase()}const ge$1=/[A-Za-z0-9_-]/;let me$1 = class me{constructor(t,e){this.source=t,this.pos=e;}peek(t=0){return this.source[this.pos+t]||""}consume(){return this.source[this.pos++]||""}eof(){return this.pos>=this.source.length}skipWhitespace(){for(;!this.eof()&&/\s/.test(this.peek());)this.pos++;}readIdent(){const t=this.pos;for(;!this.eof()&&ge$1.test(this.peek());)this.pos++;return this.source.slice(t,this.pos)}readQuoted(){const t=this.consume();this.assert('"'===t||"'"===t,"expected quote");const e=this.pos;for(;!this.eof()&&this.peek()!==t;)"\\"===this.peek()?this.pos+=2:this.pos++;this.assert(!this.eof(),"unterminated string");const n=this.source.slice(e,this.pos);return this.pos++,n.replace(/\\(.)/g,"$1")}assert(t,e){t||nt$4(364,String(this.pos+1),e,this.source);}};function ye$1(t){const e=new Set,n=[],o=[];if(t.skipWhitespace(),"*"===t.peek())t.consume();else if(ge$1.test(t.peek())){const n=t.readIdent();n&&e.add(n.toUpperCase());}for(;!t.eof();){const e=t.peek();if("."===e){t.consume();const e=t.readIdent();t.assert(""!==e,'expected class name after "."'),o.push(e);}else if("#"===e){t.consume();const e=t.readIdent();t.assert(""!==e,'expected id after "#"'),n.push(le$2("id",e));}else {if("["!==e)break;{t.consume(),t.skipWhitespace();const e=t.readIdent();t.assert(""!==e,'expected attribute name after "["'),t.skipWhitespace();let o=true;if("="===t.peek()){t.consume(),t.skipWhitespace();const e=t.peek();'"'===e||"'"===e?o=t.readQuoted():(o=t.readIdent(),t.assert(""!==o,"expected attribute value")),t.skipWhitespace();}t.assert("]"===t.peek(),'expected "]"'),t.consume(),n.push(le$2(e,o));}}}return o.length>0&&n.push(ce$1(o)),{predicates:n,tags:e}}function xe$2(t){const e=new me$1(t,0),n=[];for(;;){const t=ye$1(e);if(n.push(t),e.skipWhitespace(),e.eof())break;e.assert(","===e.peek(),'expected "," (selector lists are the only supported combinator)'),e.consume(),e.skipWhitespace();}if(1===n.length)return se$1(n[0].tags,n[0].predicates);const o=new Set;for(const t of n)for(const e of t.tags)o.add(e);return se$1(o,[(t,e)=>{for(const o of n){const n=t.nodeName;if(o.tags.size>0&&!o.tags.has(n))continue;let r=true;for(const n of o.predicates)if(!n(t,e)){r=false;break}if(r)return  true}return  false}])}function Se$1(t){return t}function $e$1(t,e,n){return ht$4($t$4,t,e,n)}const be=/* @__PURE__ */$e$1("textFormat",()=>0),ke$1=/* @__PURE__ */$e$1("textStyle",()=>({}));function De$1(t){if(!dc(t))return  false;if("PRE"===t.nodeName)return  true;const e=t.style.whiteSpace;return "string"==typeof e&&e.startsWith("pre")}function we$1(t){if(Ls(t))return  true;if(!dc(t))return  false;const e=t.style.display;return e?e.startsWith("inline"):!mc(t)&&pc(t)}const Oe$1=/* @__PURE__ */$e$1("whitespaceConfig",()=>({isInline:we$1,preservesWhitespace:De$1}));function We(t){return Mr(t)||Li(t)&&!t.isInline()}function Re(t,e){const n=[];let o=[];const r=()=>{0!==o.length&&(n.push(e().splice(0,0,o)),o=[]);};for(const s of t)if(We(s)){if(r(),Pi(s)){const t=Re(s.getChildren(),e);s.splice(0,s.getChildrenSize(),t);}n.push(s);}else o.push(s);return r(),n}function Fe$1(t,e){if(!dc(e))return t;const n=e.style.textAlign;if(!Be$1(n))return t;for(const e of t)Mr(e)&&""===e.getFormatType()&&e.setFormat(n);return t}function Te$2(t,e,n){1===t.length&&qi(t[0])&&(t=[]);const o=ts();if(dc(n)){const t=n.style.textAlign;Be$1(t)&&o.setFormat(t);}return [o.splice(0,0,t)]}const Pe$1={$accepts:We,$packageRun:Te$2,name:"BlockSchema"},Le$1=pe$1,Ue=new Set(["center","end","justify","left","right","start"]);function Be$1(t){return Ue.has(t)}const Ve={B:{fontWeight:"bold"},EM:{fontStyle:"italic"},I:{fontStyle:"italic"},S:{textDecoration:"line-through"},STRONG:{fontWeight:"bold"},SUB:{verticalAlign:"sub"},SUP:{verticalAlign:"super"},U:{textDecoration:"underline"}},qe$1={CODE:C,MARK:T};const Ge=new Set(["font-weight","font-style","text-decoration","vertical-align"]);const He$1=/* @__PURE__ */Se$1({$import:(t,e)=>{const n=t.get(be),o=Ve[e.nodeName],r=function(t){return {fontStyle:t.style.fontStyle,fontWeight:t.style.fontWeight,textDecoration:t.style.textDecoration,verticalAlign:t.style.verticalAlign}}(e),s=o?(i=o,{fontStyle:(c=r).fontStyle||i.fontStyle,fontWeight:c.fontWeight||i.fontWeight,textDecoration:c.textDecoration||i.textDecoration,verticalAlign:c.verticalAlign||i.verticalAlign}):r;var i,c;let l=(u=n,f=function(t){let e=0,n=0;const{fontWeight:o,fontStyle:r,textDecoration:s,verticalAlign:i}=t;if("700"===o||"bold"===o?e|=p:"normal"!==o&&"400"!==o||(n|=p),"italic"===r?e|=y:"normal"===r&&(n|=y),s){const t=s.split(" ");t.includes("underline")&&(e|=x),t.includes("line-through")&&(e|=m),t.includes("none")&&(n|=x|m);}return "sub"===i?(e|=S,n|=v):"super"===i?(e|=v,n|=S):"baseline"===i&&(n|=S|v),{clear:n,set:e}}(s),u&~f.clear|f.set);var u,f;const a=qe$1[e.nodeName];return a&&(l|=a),l===n?t.$importChildren(e):t.$importChildren(e,{context:[ft$3(be,l)]})},match:Le$1.tag("b","strong","em","i","code","mark","s","sub","sup","u","span"),name:"@lexical/html/inline-format"});function Je$1(t,e,n){let o=t;for(;;){let t=null;for(;null===(t=e?o.nextSibling:o.previousSibling);){const t=o.parentNode;if(null===t)return null;o=t;}if(o=t,!n.isInline(o))return null;let r=o;for(;null!==(r=e?o.firstChild:o.lastChild);)o=r;if(Ls(o))return o;if("BR"===o.nodeName)return null}}function Qe(t,e){return 0!==e&&Xo(t)?t.setFormat(e):t}function Ke$1(t,e){if(Xo(t)){const n=function(t){let e="";for(const n in t)Ge.has(n)||(e+=`${n}: ${t[n]}; `);return e.trimEnd()}(e);""!==n&&t.setStyle(n);}return t}const Ye=/* @__PURE__ */Se$1({$import:(t,e)=>{const n=t.get(be),o=t.get(ke$1),r=t.get(Oe$1);if(function(t,e){let n=t.parentNode;for(;null!==n;){if(e.preservesWhitespace(n))return  true;n=n.parentNode;}return  false}(e,r)){const t=Vr(e.textContent||"");for(const e of t)Qe(e,n),Ke$1(e,o);return t}const s=function(t,e){let n=(t.textContent||"").replace(/\r/g,"").replace(/[ \t\n]+/g," ");if(0===n.length)return "";if(" "===n[0]){let o=t,r=true;for(;null!==o&&null!==(o=Je$1(o,false,e));){const t=o.textContent||"";if(t.length>0){/[ \t\n]$/.test(t)&&(n=n.slice(1)),r=false;break}}r&&(n=n.slice(1));}if(n.length>0&&" "===n[n.length-1]){let o=t,r=true;for(;null!==o&&null!==(o=Je$1(o,true,e));)if((o.textContent||"").replace(/^( |\t|\r?\n)+/,"").length>0){r=false;break}r&&(n=n.slice(0,-1));}return n}(e,r);if(""===s)return [];const i=Go(s);return Qe(i,n),Ke$1(i,o),[i]},match:Le$1.text(),name:"@lexical/html/#text"}),Ze=/* @__PURE__ */Se$1({$import:()=>[],match:Le$1.tag("script","style"),name:"@lexical/html/script-style-ignore"}),Xe=/* @__PURE__ */Se$1({$import:(t,e)=>Yi(e)||Gi(e)?[]:[Vi()],match:Le$1.tag("br"),name:"@lexical/html/br"}),tn$1=/* @__PURE__ */Se$1({$import:(t,e)=>{const n=ts();if(Fc(n,e),Dc(e,n),""===n.getFormatType()){const t=e.getAttribute("align");t&&Be$1(t)&&n.setFormat(t);}return Pc(n,e),[n.splice(0,0,t.$importChildren(e))]},match:Le$1.tag("p"),name:"@lexical/html/p"}),en$1=/* @__PURE__ */Se$1({$import:(t,n,o)=>Cc().hasNode(hn$1)?[pn$1()]:o(),match:Le$1.tag("hr"),name:"@lexical/html/hr"});[Ze,tn$1,en$1,/* @__PURE__ */Se$1({$import:(t,e,n)=>mc(e)?Fe$1(t.$importChildren(e,{schema:Pe$1}),e):n(),match:Le$1.any(),name:"@lexical/html/transparent-block"}),Ye,Xe,He$1];function on$1(t,e){const n=[];let o=0,r=0;for(;o<t.length&&r<e.length;)t[o]<=e[r]?n.push(t[o++]):n.push(e[r++]);for(;o<t.length;)n.push(t[o++]);for(;r<e.length;)n.push(e[r++]);return n}function rn$1(t){const e=[],n=new Map,o=[],r=[],s=[],i=new Set;t.forEach((t,c)=>{const l=function(t){const e=t[re$2];return void 0===e&&nt$4(360),e}(t.match),u=t.name||function(t,e){if("text"===t.kind)return `#text@${e}`;if("comment"===t.kind)return `#comment@${e}`;if(0===t.tags.size)return `*@${e}`;const n=Array.from(t.tags).join(",").toLowerCase();return `${n}@${e}`}(l,c);if(t.name&&i.add(t.name),e.push({$import:t.$import,name:u,predicate:l.predicate}),"text"===l.kind)r.push(c);else if("comment"===l.kind)s.push(c);else if(0===l.tags.size)o.push(c);else for(const t of l.tags){let e=n.get(t);e||(e=[],n.set(t,e)),e.push(c);}});const c=new Map;if(0===o.length)for(const[t,e]of n)c.set(t,e);else for(const[t,e]of n)c.set(t,on$1(e,o));return {byTag:c,commentIndices:s,rules:e,textIndices:r,wildcardIndices:o}}function ln$1(t){const e=[];for(const n of t)if(un$1(n))for(const t of n.rules)e.push(t);else e.push(n);return e}function un$1(t){return "object"==typeof t&&null!==t&&"__type"in t&&"CompiledOverlayRules"===t.__type}function fn$1(t){const e=ln$1(t);return {__type:"CompiledOverlayRules",dispatch:rn$1(e),rules:e}}/* @__PURE__ */Se$1({$import:(t,e)=>t.$importChildren(e),match:pe$1.any(),name:"@lexical/html/default-hoist"});const Cn$1={any:pe$1.any,comment:pe$1.comment,css:xe$2,tag:pe$1.tag,text:pe$1.text},bn$1=new Set(["STYLE","SCRIPT"]);function kn$1(t,e){mt$2(e);const n=Ks(e)?e.body.childNodes:e.childNodes,r=[],s=[];for(const e of n)if(!bn$1.has(e.nodeName)){const n=Nn$1(e,t,s,false);if(null!==n)for(const t of n)r.push(t);}return function(t){for(const e of t)e.getParent()&&e.getNextSibling()instanceof Hi&&e.insertAfter(Vi());for(const e of t){const t=e.getParent();t&&t.splice(e.getIndexWithinParent(),1,e.getChildren());}}(s),r}function Dn$1(t,n=null,o=Cc()){return Et$4([ft$3(kt$3,true)],o)(()=>{const e=tl(),r=Nt$4(o),s=cr(n)?ta(n.anchor.getNode()):null,i=t.append.bind(t);for(const t of (Pi(s)?s:e).getChildren())Mn$1(o,t,i,n,r);return t})}function On$1(t,e=null){return ("undefined"==typeof document||"undefined"==typeof window&&void 0===global.window)&&nt$4(338),hi(t),Dn$1(Xl().createElement("div"),e,t).innerHTML}function Mn$1(e,n,o,i=null,c=Sc(e)){let l=c.$shouldInclude(n,i,e);const u=c.$shouldExclude(n,i,e);let f=n;null!==i&&Xo(n)&&(f=L(i,n,"clone"));const a=c.$exportDOM(f,e),{element:d,after:p,append:g,$getChildNodes:m}=a;if(!d)return  false;const y=Xl().createDocumentFragment(),S=m?m():Pi(f)?f.getChildren():[],$=l&&ur(i)&&Pi(n)?null:i,v=y.append.bind(y);for(const t of S){const o=Mn$1(e,t,v,$,c);!l&&o&&c.$extractWithChild(n,t,i,"html",e)&&(l=true);}if(l&&!u){if((dc(d)||gc(d))&&(g?g(y):d.append(y)),o(d),p){const t=p.call(f,d);t&&(gc(d)?d.replaceChildren(t):d.replaceWith(t));}}else o(y);return l}function Nn$1(t,e,n,o,r=new Map,s){const i=[];if(bn$1.has(t.nodeName))return i;let c=null;const l=function(t,e){const{nodeName:n}=t,o=e._htmlConversions.get(n.toLowerCase());let r=null;if(void 0!==o)for(const e of o){const n=e(t);null!==n&&(null===r||(r.priority||0)<=(n.priority||0))&&(r=n);}return null!==r?r.conversion:null}(t,e),u=l?l(t):null;let f=null;if(null!==u){f=u.after;const e=u.node;if(c=Array.isArray(e)?e[e.length-1]:e,null!==c){for(const[,t]of r)if(c=t(c,s),!c)break;c&&i.push(...Array.isArray(e)?e:[c]);}null!=u.forChild&&r.set(t.nodeName,u.forChild);}const a=t.childNodes;let d=[];const p=(null==c||!Kl(c))&&(null!=c&&Mr(c)||o);for(let t=0;t<a.length;t++)d.push(...Nn$1(a[t],e,n,p,new Map(r),c));if(null!=f&&(d=f(d)),mc(t)&&(d=En$1(t,d,p?()=>{const t=new Hi;return n.push(t),t}:ts)),null==c)if(d.length>0)for(const t of d)i.push(t);else mc(t)&&function(t){if(null==t.nextSibling||null==t.previousSibling)return  false;return pc(t.nextSibling)&&pc(t.previousSibling)}(t)&&i.push(Vi());else Pi(c)&&c.append(...d);return i}function En$1(t,e,n){const o=t.style.textAlign,r=[];let s=[];for(let t=0;t<e.length;t++){const i=e[t];if(Mr(i))o&&!i.getFormat()&&i.setFormat(o),r.push(i);else if(s.push(i),t===e.length-1||t<e.length-1&&Mr(e[t+1])){const t=n();t.setFormat(o),t.append(...s),r.push(t),s=[];}}return r}

	/**
	 * Copyright (c) Meta Platforms, Inc. and affiliates.
	 *
	 * This source code is licensed under the MIT license found in the
	 * LICENSE file in the root directory of this source tree.
	 *
	 */

	function et$2(r,i,l=null){const c=Gl(l),s=l?ql(l):[],u=null!==l&&s.length>0;if(u&&"function"==typeof c.caretPositionFromPoint){const t=c.caretPositionFromPoint(r,i,{shadowRoots:s});if(null!==t&&function(t,e){for(let n=t;null!==n;){if(n===e)return  true;n=Nl(n);}return  false}(t.offsetNode,l))return {node:t.offsetNode,offset:t.offset}}if(u){const t=l.getRootNode();if(jl(t)){const e=t.elementFromPoint(r,i);if(null!==e&&l.contains(e)){const t=function(t,e,n,o){const r=o.createRange(),i=t=>e<t.top?t.top-e:e>t.bottom?e-t.bottom:0,l=e=>t<e.left?e.left-t:t>e.right?t-e.right:0,c=o.createTreeWalker(n,NodeFilter.SHOW_TEXT);let s=null,u=1/0,a=1/0;for(let t=c.nextNode();t;t=c.nextNode()){r.selectNodeContents(t);for(const e of r.getClientRects()){const n=i(e),o=l(e);(n<u||n===u&&o<a)&&(u=n,a=o,s=t);}}if(null===s)return null;let f=0,p=1/0,d=1/0;for(let e=0;e<=s.length;e++){r.setStart(s,e),r.collapse(true);const n=r.getBoundingClientRect(),o=i(n),l=Math.abs(t-n.left);(o<p||o===p&&l<d)&&(p=o,d=l,f=e);}return {node:s,offset:f}}(r,i,e,c);if(null!==t)return t}}}if("function"==typeof c.caretRangeFromPoint){const t=c.caretRangeFromPoint(r,i);return null===t?null:{node:t.startContainer,offset:t.startOffset}}if("function"==typeof c.caretPositionFromPoint){const t=c.caretPositionFromPoint(r,i);return null===t?null:{node:t.offsetNode,offset:t.offset}}return null}function nt$3(t,...e){const n=new URL("https://lexical.dev/docs/error"),o=new URLSearchParams;o.append("code",t);for(const t of e)o.append("v",t);throw n.search=o.toString(),Error(`Minified Lexical error #${t}; visit ${n.toString()} for the full message or use the non-minified dev environment for full errors and additional helpful warnings.`)}const ot$2={"application/x-lexical-editor":0,"text/html":10,"text/plain":20,"text/uri-list":30};function rt$3(t){if(window.trustedTypes&&window.trustedTypes.createPolicy){return window.trustedTypes.createPolicy("lexical",{createHTML:t=>t}).createHTML(t)}return t}const it$3=(t,e)=>{if(!cr(e))return e.insertRawText(t),true;const n=t=>{const e=Lr();cr(e)&&t(e);};return jr(t,{linebreak:()=>n(t=>t.insertParagraph()),tab:()=>n(t=>t.insertNodes([tr()])),text:t=>n(e=>e.insertText(t))}),true},lt$3={"application/x-lexical-editor":[(t,e,n)=>{try{const n=Cc(),o=JSON.parse(t);if(o&&o.namespace===n._config.namespace&&Array.isArray(o.nodes)){return Mt$3(n,$t$3(o.nodes),e),!0}}catch(t){console.error(t);}return n()}],"text/html":[(t,e,n)=>{try{const n=Cc(),o=(new DOMParser).parseFromString(rt$3(t),"text/html");return Mt$3(n,kn$1(n,o),e),!0}catch(t){return console.error(t),n()}}],"text/plain":[it$3],"text/uri-list":[it$3]};function ct$2(t,e,n,o){if(!t)return  false;const r=i=>!!t[i]&&t[i](e,n,r.bind(null,i-1),o);return r(t.length-1)}function st$3(t,e,n){const o=e.getData("text/plain");for(const r of function(t){return Object.keys(t.$importMimeType).filter(e=>void 0!==t.$importMimeType[e]).sort((e,n)=>{const o=t.priority[e],r=t.priority[n];return void 0===o&&void 0===r?e<n?-1:e>n?1:0:void 0===o?1:void 0===r?-1:o-r})}(t)){const i=e.getData(r);if(i&&(("text/html"!==r||i!==o)&&ct$2(t.$importMimeType[r],i,n,e)))return  true}return  false}const ut$3={$importMimeType:lt$3,$insertDataTransfer:(t,e)=>st$3({$importMimeType:lt$3,priority:ot$2},t,e),priority:ot$2};const at$2=/* @__PURE__ */pu({build:(t,e)=>({$importMimeType:e.$importMimeType,$insertDataTransfer:(t,n)=>st$3(e,t,n),priority:e.priority}),config:/* @__PURE__ */xu({$importMimeType:lt$3,priority:ot$2}),mergeConfig(t,e){const n=Cu(t,e);if(e.$importMimeType){const o={...t.$importMimeType};for(const[t,n]of Object.entries(e.$importMimeType))if(n){const e=o[t];o[t]=e?[...e,...n]:n;}n.$importMimeType=o;}return e.priority&&(n.priority={...t.priority,...e.priority}),n},name:"@lexical/clipboard/Import"});function pt$3(t,e=Lr()){return null==e&&nt$3(166),cr(e)&&e.isCollapsed()||0===e.getNodes().length?"":On$1(t,e)}function dt$4(t,e=Lr()){return null==e&&nt$3(166),cr(e)&&e.isCollapsed()||0===e.getNodes().length?null:JSON.stringify(wt$3(t,e))}function gt$2(t,e,n){(function(){const t=dn$1(at$2.name);return t?t.output:ut$3})().$insertDataTransfer(t,e);}const yt$2="application/x-lexical-drag";function xt$2(t,e){const n={editorKey:e.getKey()};t.setData(yt$2,JSON.stringify(n));}function ht$3(t,e,n){const o=t.dataTransfer;if(null===o)return  false;const r=function(t){const e=t.getData(yt$2);if(!e)return null;let n;try{n=JSON.parse(e);}catch(t){return null}return null!==(o=n)&&"object"==typeof o&&"editorKey"in o&&"string"==typeof o.editorKey?n:null;var o;}(o);if(null===r)return  false;const i=function(t,e){const n=et$2(t.clientX,t.clientY,e.getRootElement());if(null===n)return null;const o=Xs(n.node);if(null===o)return null;if(Xo(o))return Pa(o,"next",n.offset);if(Pi(o))return uu(o,n.offset,"next");const r=o.getParent();return null===r?null:uu(r,o.getIndexWithinParent()+1,"next")}(t,e);if(null===i)return  false;const l=gu(i);if(null===l)return  false;const c=r.editorKey===e.getKey(),u=Lr();if(c){if(!cr(u)||u.isCollapsed())return  false;if(function(t,e){const{anchor:n,focus:o}=au(eu(e),"next");return ja(n,t)<0&&ja(t,o)<0}(i,u))return t.preventDefault(),true;u.removeText();}if(!l.origin.isAttached())return t.preventDefault(),true;if(n(o,Za($a(l)),e),!c){const t=e.getRootElement(),n=t?t.ownerDocument:null,o=n?function(t,e){for(const n of Yl(e)){const e=Ds(n);if(Ms(e)&&e.getKey()===t&&dc(n))return n}return null}(r.editorKey,n):null;null!==o&&o.dispatchEvent(new InputEvent("beforeinput",{bubbles:true,cancelable:true,inputType:"deleteByDrag"}));}return t.preventDefault(),true}function Tt$4(t,e){return ht$3(t,e,gt$2)}function Mt$3(t,e,n){t.dispatchCommand(Le$3,{nodes:e,selection:n})||(n.insertNodes(e),function(t){if(cr(t)&&t.isCollapsed()){const e=t.anchor;let n=null;const o=Xa(e,"previous");if(o)if(ba(o))n=o.origin;else {const t=Ha(o,La(tl(),"next").getFlipped());for(const e of t){if(Xo(e.origin)){n=e.origin;break}if(Pi(e.origin)&&!e.origin.isInline())break}}if(n&&Xo(n)){const e=n.getFormat(),o=n.getStyle();t.format===e&&t.style===o||(t.format=e,t.style=o,t.dirty=true);}}}(n));}function vt$3(t,e,n,o=[]){let r=null===e||n.isSelected(e);const i=Pi(n)&&n.excludeFromCopy("html");let l=n;null!==e&&Xo(l)&&(l=L(e,l,"clone"));const c=Pi(l)?l.getChildren():[],s=function(t){const e=t.exportJSON(),n=t.constructor;if(e.type!==n.getType()&&nt$3(58,n.name),Pi(t)){const t=e.children;Array.isArray(t)||nt$3(59,n.name);}return e}(l);Xo(l)&&0===l.getTextContentSize()&&(r=false);const u=r&&ur(e)&&Pi(n)?null:e;for(let o=0;o<c.length;o++){const i=c[o],l=vt$3(t,u,i,s.children);!r&&Pi(n)&&l&&n.extractWithChild(i,e,"clone")&&(r=true);}if(r&&!i){const e=na(l);if(e.length>0){const n={};for(const o of e){const e=oa(l,o);null===e&&nt$3(366,l.constructor.name,o);const r=[];vt$3(t,null,e,r),1===r.length&&r[0].type===e.getType()||nt$3(385,o,l.constructor.name,String(r.length),String(r.length>0?r[0].type:"none")),n[o]=r[0];}s.$slots=n;}}if(r&&!i)o.push(s);else if(Array.isArray(s.children))for(let t=0;t<s.children.length;t++){const e=s.children[t];o.push(e);}return r}function wt$3(t,e){const n=[],o=tl(),r=cr(e)?e.anchor.getNode():ur(e)?e.getNodes()[0]??null:null,i=null!==r?ta(r):null,l=(Pi(i)?i:o).getChildren();for(let o=0;o<l.length;o++){vt$3(t,e,l[o],n);}return {namespace:t._config.namespace,nodes:n}}function $t$3(t){const e=[];for(const n of t)e.push(Si(n));return e}let Ct$3=null;async function Dt$4(t,e,n){if(null!==Ct$3)return  false;if(null!==e)return new Promise((o,r)=>{t.update(()=>{o(Nt$3(t,e,n));});});const o=t.getRootElement(),r=t._window||window,i=r.document,l=Hl(r);if(null===o||null===l)return  false;const c=i.createElement("span");c.style.position="fixed",c.style.top="-1000px",c.append(i.createTextNode("#")),o.append(c);const s=i.createRange();return s.setStart(c,0),s.setEnd(c,1),l.removeAllRanges(),l.addRange(s),new Promise((e,o)=>{const l=t.registerCommand(vn$1,o=>(Mt$4(o,ClipboardEvent)&&(l(),null!==Ct$3&&(r.clearTimeout(Ct$3),Ct$3=null),e(Nt$3(t,o,n))),true),ls);Ct$3=r.setTimeout(()=>{l(),Ct$3=null,e(false);},50),i.execCommand("copy"),c.remove();})}function Nt$3(t,e,n){if(void 0===n){const e=Hl(t._window),o=Lr();if(!o||o.isCollapsed())return  false;if(!e)return  false;const r=tc(e,t.getRootElement()),i=r.anchorNode,l=r.focusNode;if(null!==i&&null!==l&&!Os(t,i,l))return  false;n=Rt$3(o);}e.preventDefault();const o=e.clipboardData;return null!==o&&(St$3(o,n),true)}const Pt$4=[["text/html",pt$3],["application/x-lexical-editor",dt$4]];function Rt$3(t=Lr()){return function(t,e){const n={"text/plain":""};for(const[o,r]of Object.entries(t))if(r){const t=Et$3(r,e);null!==t&&(n[o]=t);}return n}(Ot$3(),t)}function St$3(t,e){for(const[n]of Pt$4) void 0===e[n]&&t.setData(n,"");for(const n in e){const o=e[n];void 0!==o&&t.setData(n,o);}}function Ot$3(t=Cc()){const e=sn$1(t,Kt$2.name);return e?e.output:Ft$3}const Ft$3={"application/x-lexical-editor":[(t,e)=>t?dt$4(Cc(),t):e()],"text/html":[(t,e)=>t?pt$3(Cc(),t):e()],"text/plain":[(t,e)=>t?t.getTextContent():e()]};function Et$3(t,e){const n=o=>t[o]?t[o](e,n.bind(null,o-1)):null;return n(t.length-1)}const Kt$2=/* @__PURE__ */pu({build:(t,e,n)=>e.$exportMimeType,config:/* @__PURE__ */xu({$exportMimeType:Ft$3}),mergeConfig(t,e){const n=Cu(t,e);if(e.$exportMimeType){const o={...t.$exportMimeType};for(const[t,n]of Object.entries(e.$exportMimeType))if(n){const e=o[t];o[t]=e?[...e,...n]:n;}n.$exportMimeType=o;}return n},name:"@lexical/clipboard/GetClipboardData"});

	/**
	 * Copyright (c) Meta Platforms, Inc. and affiliates.
	 *
	 * This source code is licensed under the MIT license found in the
	 * LICENSE file in the root directory of this source tree.
	 *
	 */

	const dt$3=/* @__PURE__ */Se$1({$import:(e,t)=>{const n=kt$2(t.nodeName.toLowerCase());return Dc(t,n),Fc(n,t),Pc(n,t),[n.splice(0,0,e.$importChildren(t))]},match:Cn$1.tag("h1","h2","h3","h4","h5","h6"),name:"@lexical/rich-text/heading"}),pt$2=/* @__PURE__ */Se$1({$import:(e,t)=>{const n=St$2();return Fc(n,t),Dc(t,n),Pc(n,t),[n.splice(0,0,e.$importChildren(t))]},match:Cn$1.tag("blockquote"),name:"@lexical/rich-text/blockquote"});/* @__PURE__ */Se$1({$import:(e,t)=>{const n=St$2({shadowRoot:true});return Fc(n,t),Dc(t,n),Pc(n,t),[n.splice(0,0,e.$importChildren(t,{schema:Pe$1}))]},match:Cn$1.tag("blockquote"),name:"@lexical/rich-text/blockquote-shadow-root"});[dt$3,pt$2,/* @__PURE__ */Se$1({$import:(e,t,n)=>{const r=t.firstChild;return r&&(dc(o=r)&&"SPAN"===o.nodeName&&"26pt"===o.style.fontSize)?e.$importChildren(t):n();var o;},match:Cn$1.tag("p"),name:"@lexical/rich-text/google-docs-title-p"}),/* @__PURE__ */Se$1({$import:(e,t,n)=>"26pt"!==t.style.fontSize?n():[kt$2("h1").splice(0,0,e.$importChildren(t))],match:Cn$1.tag("span"),name:"@lexical/rich-text/google-docs-title-span"})];const vt$2=/* @__PURE__ */Fe$3("DRAG_DROP_PASTE_FILE"),Dt$3=/* @__PURE__ */mt$3("shadowRoot",{parse:Boolean});let Nt$2 = class Nt extends Di{static getType(){return "quote"}static clone(e){return new Nt(e.__key)}$config(){return this.config("quote",{extends:Di,stateConfigs:[{flat:true,stateConfig:Dt$3}]})}isShadowRoot(){return xt$4(this,Dt$3)}setIsShadowRoot(e){return St$4(this,Dt$3,e)}createDOM(e){const t=Xl().createElement("blockquote");return vu(t,e.theme.quote),t}updateDOM(e,t){return  false}static importDOM(){return {blockquote:e=>({conversion:Ot$2,priority:0})}}exportDOM(e){const{element:t}=super.exportDOM(e);if(dc(t)){this.isEmpty()&&t.append(Xl().createElement("br"));const e=this.getFormatType();e&&(t.style.textAlign=e);const n=this.getDirection();n&&(t.dir=n);}return {element:t}}static importJSON(e){return St$2().updateFromJSON(e)}exportJSON(){return super.exportJSON()}insertNewAfter(e,t){const n=ts(),r=this.getDirection();return n.setDirection(r),this.insertAfter(n,t),n}collapseAtStart(){if(this.isShadowRoot()){for(const e of this.getChildren())this.insertBefore(e);return this.remove(),true}const e=ts();return this.getChildren().forEach(t=>e.append(t)),this.replace(e),true}canMergeWhenEmpty(){return  true}};function St$2(e){const t=Bl(new Nt$2);return e&&e.shadowRoot?t.setIsShadowRoot(true):t}function bt$2(e){return e instanceof Nt$2}let _t$2 = class _t extends Di{__tag;static getType(){return "heading"}static clone(e){return new _t(e.__tag,e.__key)}afterCloneFrom(e){super.afterCloneFrom(e),this.__tag=e.__tag;}constructor(e="h1",t){super(t),this.__tag=e;}getTag(){return this.getLatest().__tag}setTag(e){const t=this.getWritable();return t.__tag=e,t}createDOM(e){const t=this.__tag,n=Xl().createElement(t),r=e.theme.heading;if(void 0!==r){const e=r[t];vu(n,e);}return n}updateDOM(e,t,n){return e.__tag!==this.__tag}static importDOM(){return {h1:e=>({conversion:wt$2,priority:0}),h2:e=>({conversion:wt$2,priority:0}),h3:e=>({conversion:wt$2,priority:0}),h4:e=>({conversion:wt$2,priority:0}),h5:e=>({conversion:wt$2,priority:0}),h6:e=>({conversion:wt$2,priority:0}),p:e=>{const t=e.firstChild;return null!==t&&Tt$3(t)?{conversion:()=>({node:null}),priority:3}:null},span:e=>Tt$3(e)?{conversion:e=>({node:kt$2("h1")}),priority:3}:null}}exportDOM(e){const{element:t}=super.exportDOM(e);if(dc(t)){this.isEmpty()&&t.append(Xl().createElement("br"));const e=this.getFormatType();e&&(t.style.textAlign=e);const n=this.getDirection();n&&(t.dir=n);}return {element:t}}static importJSON(e){return kt$2(e.tag).updateFromJSON(e)}updateFromJSON(e){return super.updateFromJSON(e).setTag(e.tag)}exportJSON(){return {...super.exportJSON(),tag:this.getTag()}}insertNewAfter(e,t=true){const n=e?e.anchor.offset:0,r=this.getLastDescendant(),o=!r||e&&e.anchor.key===r.getKey()&&n===r.getTextContentSize()||!e?ts():kt$2(this.getTag()),i=this.getDirection();if(o.setDirection(i),this.insertAfter(o,t),0===n&&!this.isEmpty()&&e){const e=ts();e.select(),this.replace(e,true);}return o}collapseAtStart(){if(this.isEmpty()){const e=ts();this.getChildren().forEach(t=>e.append(t)),this.replace(e);}return  true}extractWithChild(){return  true}};function Tt$3(e){return "span"===e.nodeName.toLowerCase()&&"26pt"===e.style.fontSize}function wt$2(e){const t=e.nodeName.toLowerCase();let n=null;return "h1"!==t&&"h2"!==t&&"h3"!==t&&"h4"!==t&&"h5"!==t&&"h6"!==t||(n=kt$2(t),Dc(e,n),Fc(n,e),Pc(n,e)),{node:n}}function Ot$2(e){const t=St$2();return Fc(t,e),Dc(e,t),Pc(t,e),{node:t}}function kt$2(e="h1"){return Bl(new _t$2(e))}function Kt$1(e){return e instanceof _t$2}function Et$2(e){const t=Xs(e);return Li(t)}function Ft$2(e,t,n,r){let o=false,i=null;if(e.isCollapsed()&&"text"===e.anchor.type){const t=e.anchor.getNode();if(Xo(t)){i=t;const r=e.anchor.offset,s=r===t.getTextContentSize()&&null===t.getNextSibling(),a=0===r&&null===t.getPreviousSibling();o="end"===n&&s||"start"===n&&a||"both"===n&&(s||a);}}let s=false;for(const[n,a]of Object.entries(r)){if(null==a||!a[t])continue;const r=n;if(a.onlyAtBoundary){if(!(o&&i&&Xo(i)&&i.hasFormat(r)))continue;s=true;}e.hasFormat(r)&&e.toggleFormat(r);}s&&e.setStyle("");}const It$3={capitalize:{enter:true,space:true,tab:true},lowercase:{enter:true,space:true,tab:true},uppercase:{enter:true,space:true,tab:true}};function Rt$2(e,t){return function(e,t){if(!e.isCollapsed())return  false;const n=Xa(e.focus,t),r=Jc(n.origin,Ll);if(!r)return  false;const o=e.focus.getNode();if(!r.is(o)&&!Al(o,r))return  false;const i=Ha(n,Da(r,t));if(i.getTextSlices().some(e=>e&&e.getTextContentSize()>0))return  false;const s=Ha(i.anchor.getSiblingCaret(),i.focus);let a=s.anchor.origin;for(const e of s){if(!wa(e)||!e.origin.is(a.getParent()))return  false;a=e.origin;}let c=r;for(const e of Ua(Da(r,t))){if(!e.origin.is(c.getParent())){if(Ul(e.origin)){const e=Da(c,t);return Za(Ha(e,e)),true}break}if(!Ll(e.origin))break;c=e.origin;}return  false}(e,t)||function(e,t){if(!e.isCollapsed()||"element"!==e.anchor.type)return  false;const n=Xa(e.anchor,t).getNodeAtCaret();return !(!Ll(n)||n.isInline()||(Za($a(su(La(n,t)))),0))}(e,t)}function At$3(e){return Li(e)&&!e.isInline()&&!e.isIsolated()&&e.isKeyboardSelectable()}function Pt$3(e){const t=Pr();t.add(e),el(t);}function zt$3(e,t){if(!e.isCollapsed())return  false;const n=e.focus,r=n.getNode(),o=t?"previous":"next",i=Xa(n,o);if("element"===n.type&&Pi(r)&&(zi(r)||Ll(r))){const e=i.getNodeAtCaret();return !(null===e||!At$3(e))&&(Pt$3(e.__key),true)}const s=Jc(Pi(r)?r:r.getParentOrThrow(),e=>Pi(e)&&!e.isInline()&&Kl(e.getParent()));if(null===s)return  false;const a=Da(s,o).getNodeAtCaret();if(null===a||!At$3(a))return  false;if(0===s.getTextContentSize())return Pt$3(a.__key),true;const c=Cc().getRootElement();if(null===c)return  false;const l=Hl(c.ownerDocument.defaultView);if(null===l||0===l.rangeCount)return  false;const u=l.anchorNode,f=l.anchorOffset,g=l.focusNode,d=l.focusOffset;l.modify("move",t?"backward":"forward","line");const p=l.anchorNode,m=l.anchorOffset;if(null===p)return Mt$2(l,u,f,g,d),false;const h=Xs(p);if(Mt$2(l,u,f,g,d),null===h)return  false;if(p===u&&m===f)return Pt$3(a.__key),true;return !h.is(s)&&!Al(h,s)&&(Pt$3(a.__key),true)}function Mt$2(e,t,n,r,o){null!==t&&null!==r&&e.setBaseAndExtent(t,n,r,o);}function $t$2(e,n){if(!e.isCollapsed())return  false;const r=e.focus.getNode(),o=Jc(Pi(r)?r:r.getParentOrThrow(),e=>Pi(e)&&!e.isInline());if(null===o)return  false;const i=Cc(),s=i.getRootElement();if(null===s)return  false;const a=s.ownerDocument.defaultView;if(null===a)return  false;let c=false;for(const e of o.getChildren())if(Pi(e)&&e.isInline()){const t=i.getElementByKey(e.getKey());if(null!==t){const e=a.getComputedStyle(t).display;if("inline-grid"===e||"inline-flex"===e){c=true;break}}}if(!c)return  false;const l=Da(o,n?"previous":"next").getNodeAtCaret();if(null===l||!Pi(l)){if(n){const e=o.getFirstDescendant();Xo(e)?e.select(0,0):o.select(0,0);}else {const e=o.getLastDescendant();if(Xo(e)){const t=e.getTextContentSize();e.select(t,t);}else {const e=o.getChildrenSize();o.select(e,e);}}return  true}const u=i.getElementByKey(l.getKey());if(null===u)return  false;const f=Hl(a);if(null===f||0===f.rangeCount)return  false;const g=f.getRangeAt(0).cloneRange();g.collapse(true);const d=g.getBoundingClientRect(),p=u.getBoundingClientRect(),m=p.top+p.height/2;if(d.height>0){const n=et$2(d.left,m,s);if(null!==n&&u.contains(n.node)){const t=s.ownerDocument.createRange();return t.setStart(n.node,n.offset),t.collapse(true),e.applyDOMRange(t),e.dirty=true,true}}const h=n?l.getLastDescendant():l.getFirstDescendant();if(Xo(h)){const e=n?h.getTextContentSize():0;h.select(e,e);}else {const e=l.getChildrenSize();l.select(n?e:0,n?e:0);}return  true}function Jt(e,t){const n=Da(e,t),r=n.getAdjacentCaret();null!==r&&Pi(r.origin)&&!r.origin.isInline()&&r.origin.isShadowRoot()?Za($a(n)):"next"===t?e.selectNext(0,0):e.selectPrevious();}function Lt$2(e,t,n){n.preventDefault(),n.stopPropagation();const r=e.getNodes();if(0===r.length)return  true;const o=r.map(e=>Da(e,"next")).sort(ja),i=(t?o[0]:o[o.length-1]).origin,s=Jc(i,e=>e!==i&&Pi(e)&&!e.isInline())??tl(),a=t?0:s.getChildrenSize();return s.select(a,a),true}function qt$2(a$1,c=Yt$1(It$3)){return ku(a$1.registerCommand(Ke$3,()=>{const e=Lr();return ur(e)?(e.clear(),true):(cr(e)&&Ft$2(e,"click","both",c.peek()),false)},os),a$1.registerCommand(Ue$2,e=>{const t=Lr();return cr(t)?(t.deleteCharacter(e),true):!!ur(t)&&(t.deleteNodes(),true)},os),a$1.registerCommand(qe$3,e=>{const t=Lr();return !!cr(t)&&(t.deleteWord(e),true)},os),a$1.registerCommand(Ye$1,e=>{const t=Lr();return !!cr(t)&&(t.deleteLine(e),true)},os),a$1.registerCommand(Je$3,t=>{const n=Lr();if("string"==typeof t)null!==n&&n.insertText(t);else {if(null===n)return  false;const r=t.dataTransfer;if(null!=r)gt$2(r,n);else if(cr(n)){const e=t.data;return e&&n.insertText(e),true}}return  true},os),a$1.registerCommand(Ve$2,()=>{const e=Lr();return !!cr(e)&&(e.removeText(),true)},os),a$1.registerCommand(Ge$2,e=>{const t=Lr();return !(!cr(t)&&!ur(t))&&(hr(t,e),true)},os),a$1.registerCommand(Xe$2,e=>{const t=Lr();return !(!cr(t)&&!ur(t))&&(dr(t,e),true)},os),a$1.registerCommand(mn$1,e=>{const t=Lr();if(!cr(t)&&!ur(t))return  false;const n=t.getNodes();for(const t of n){const n=Jc(t,e=>Pi(e)&&!e.isInline());null!==n&&n.setFormat(e);}return  true},os),a$1.registerCommand($e$3,e=>{const t=Lr();return !!cr(t)&&(t.insertLineBreak(e),true)},os),a$1.registerCommand(He$3,()=>{const e=Lr();return !!cr(e)&&(e.insertParagraph(),true)},os),a$1.registerCommand(gn$2,()=>{const e=tr(),t=Lr();return cr(t)&&(e.setFormat(t.format),e.setStyle(t.style)),Jr([e]),true},os),a$1.registerCommand(_n$1,()=>$t$6(e=>{const t=e.getIndent();e.setIndent(t+1);}),os),a$1.registerCommand(pn$2,()=>$t$6(e=>{const t=e.getIndent();t>0&&e.setIndent(Math.max(0,t-1));}),os),a$1.registerCommand(sn$2,e=>{const t=Lr();if(ur(t)){const n=t.getNodes();if(n.length>0)return e.preventDefault(),Jt(n[0],"previous"),true}else if(cr(t)){if(!e.shiftKey&&Rt$2(t,"previous"))return e.preventDefault(),true;if(!e.shiftKey&&zt$3(t,true))return e.preventDefault(),true;if(!e.shiftKey&&$t$2(t,true))return e.preventDefault(),true}return  false},os),a$1.registerCommand(ln$2,e=>{const t=Lr();if(ur(t)){const n=t.getNodes();if(n.length>0)return e.preventDefault(),Jt(n[0],"next"),true}else if(cr(t)){if(function(e){const t=e.focus;return "root"===t.key&&t.offset===tl().getChildrenSize()}(t))return e.preventDefault(),true;if(!e.shiftKey&&Rt$2(t,"next"))return e.preventDefault(),true;if(!e.shiftKey&&zt$3(t,false))return e.preventDefault(),true;if(!e.shiftKey&&$t$2(t,false))return e.preventDefault(),true}return  false},os),a$1.registerCommand(on$2,e=>{const t=Lr();if(ur(t)){const n=t.getNodes();if(n.length>0)return e.preventDefault(),Jt(n[0],_(n[0])?"next":"previous"),true}if(!cr(t))return  false;if(!e.shiftKey&&Rt$2(t,_(t.anchor.getNode())?"next":"previous"))return e.preventDefault(),true;if(e.shiftKey||Ft$2(t,"arrow","start",c.peek()),te$3(t,true)){const n=e.shiftKey;return e.preventDefault(),le$3(t,n,true),true}return  false},os),a$1.registerCommand(en$3,e=>{const t=Lr();if(ur(t)){const n=t.getNodes();if(n.length>0)return e.preventDefault(),Jt(n[0],_(n[0])?"previous":"next"),true}if(!cr(t))return  false;if(!e.shiftKey&&Rt$2(t,_(t.anchor.getNode())?"previous":"next"))return e.preventDefault(),true;if(e.shiftKey||Ft$2(t,"arrow","end",c.peek()),te$3(t,false)){const n=e.shiftKey;return e.preventDefault(),le$3(t,n,false),true}return  false},os),a$1.registerCommand(un$2,e=>{const t=Lr();if(!ur(t)&&Et$2(e.target))return  false;if(cr(t)){if(function(e){if(!e.isCollapsed())return  false;const{anchor:t}=e;if(0!==t.offset)return  false;const n=t.getNode();if(zi(n))return  false;const r=It$4(n);return r.getIndent()>0&&(r.is(n)||n.is(r.getFirstDescendant()))}(t))return e.preventDefault(),a$1.dispatchCommand(pn$2,void 0);if(l&&s)return  false}else if(!ur(t))return  false;return e.preventDefault(),a$1.dispatchCommand(Ue$2,true)},os),a$1.registerCommand(dn$2,e=>{const t=Lr();return !(!ur(t)&&Et$2(e.target))&&(!(!cr(t)&&!ur(t))&&(e.preventDefault(),a$1.dispatchCommand(Ue$2,false)))},os),a$1.registerCommand(cn$1,e=>{let t=Lr();if(ur(t)){const e=t.getNodes();1===e.length&&Li(e[0])&&!e[0].isInline()&&(t=e[0].selectNext());}if(!cr(t))return  false;if(Ft$2(t,"enter","both",c.peek()),null!==e){if((l||a||d)&&s)return  false;if(e.preventDefault(),e.shiftKey)return a$1.dispatchCommand($e$3,false)}return a$1.dispatchCommand(He$3,void 0)},os),a$1.registerCommand(fn$2,()=>{const e=Lr();return !!cr(e)&&(a$1.blur(),true)},os),a$1.registerCommand(yn$1,e=>{const[,r]=Ft$4(e);if(r.length>0){const n=e.clientX,o=e.clientY,i=et$2(n,o,a$1.getRootElement());if(null!==i){const{offset:e,node:t}=i,n=Xs(t);if(null!==n){const t=Dr();if(Xo(n))t.anchor.set(n.getKey(),e,"text"),t.focus.set(n.getKey(),e,"text");else {const e=n.getParentOrThrow().getKey(),r=n.getIndexWithinParent()+1;t.anchor.set(e,r,"element"),t.focus.set(e,r,"element");}const r=It$5(t);el(r);}a$1.dispatchCommand(vt$2,r);}return e.preventDefault(),true}return Tt$4(e,a$1)},os),a$1.registerCommand(xn$1,e=>{const[t]=Ft$4(e),n=Lr();return !(t&&!cr(n))&&(cr(n)&&!n.isCollapsed()&&null!==e.dataTransfer&&(St$3(e.dataTransfer,Rt$3(n)),xt$2(e.dataTransfer,a$1)),true)},os),a$1.registerCommand(Cn$2,e=>{const[n]=Ft$4(e),r=Lr();if(n&&!cr(r))return  false;const o=e.clientX,i=e.clientY,s=et$2(o,i,a$1.getRootElement());if(null!==s){const t=Xs(s.node);Li(t)&&e.preventDefault();}return  true},os),a$1.registerCommand(kn$2,()=>{const e=Lr();return yl(cr(e)&&null!==ta(e.anchor.getNode())?e:null),true},os),a$1.registerCommand(vn$1,e=>(Dt$4(a$1,Mt$4(e,ClipboardEvent)?e:null),true),os),a$1.registerCommand(Tn$1,e=>(async function(e,t){await Dt$4(t,Mt$4(e,ClipboardEvent)?e:null),t.update(()=>{const e=Lr();cr(e)?e.removeText():ur(e)&&e.getNodes().forEach(e=>e.remove());},{tag:So});}(e,a$1),true),os),a$1.registerCommand(je$2,t=>{const[,n,r]=Ft$4(t);if(n.length>0&&!r)return a$1.dispatchCommand(vt$2,n),true;if(hc(t.target)&&ws(t.target))return  false;return null!==Lr()&&(function(t,n){t.preventDefault(),n.update(()=>{const r=Lr(),o=Mt$4(t,InputEvent)||Mt$4(t,KeyboardEvent)?null:t.clipboardData;null!=o&&null!==r&&gt$2(o,r);},{tag:Co});}(t,a$1),true)},os),a$1.registerCommand(an$1,()=>{const e=Lr();return cr(e)&&Ft$2(e,"space","both",c.peek()),false},os),a$1.registerCommand(hn$2,()=>{const e=Lr();return cr(e)&&Ft$2(e,"tab","both",c.peek()),false},os),a$1.registerCommand(nn$2,e=>{const t=Lr();if(ur(t))return Lt$2(t,false,e);if(!cr(t))return  false;const{anchor:n}=t;if("element"!==n.type||0!==n.offset)return  false;const r=n.getNode();if(!Pi(r))return  false;const o=r.getFirstChild();if(!Li(o)||!o.isInline())return  false;const i=r.getKey(),s=r.selectEnd();return e.shiftKey&&s.anchor.set(i,0,"element"),e.preventDefault(),e.stopPropagation(),true},os),a$1.registerCommand(rn$2,e=>{const t=Lr();if(ur(t))return Lt$2(t,true,e);if(!cr(t))return  false;const{anchor:n,focus:r}=t,o=Jc(r.getNode(),e=>Pi(e)&&!e.isInline());if(null===o)return  false;const i=o.getFirstChild();if(!Li(i)||!i.isInline())return  false;if(Jc(n.getNode(),e=>Pi(e)&&!e.isInline())!==o)return  false;const s=o.getKey();return ("element"!==r.type||r.key!==s||0!==r.offset)&&(t.focus.set(s,0,"element"),e.shiftKey||t.anchor.set(s,0,"element"),e.preventDefault(),e.stopPropagation(),true)},os))}

	/**
	 * Copyright (c) Meta Platforms, Inc. and affiliates.
	 *
	 * This source code is licensed under the MIT license found in the
	 * LICENSE file in the root directory of this source tree.
	 *
	 */

	function ht$2(t,...e){const n=new URL("https://lexical.dev/docs/error"),r=new URLSearchParams;r.append("code",t);for(const t of e)r.append("v",t);throw n.search=r.toString(),Error(`Minified Lexical error #${t}; visit ${n.toString()} for the full message or use the non-minified dev environment for full errors and additional helpful warnings.`)}function ft$2(t){let e=1,n=t.getParent();for(;null!=n;){if(It$2(n)){const t=n.getParent();if(Rt$1(t)){e++,n=t.getParent();continue}ht$2(40);}return e}return e}function dt$2(t){const e=t.getParent();Rt$1(e)||ht$2(40);let n=e,r=e;for(;null!==r;)r=r.getParent(),Rt$1(r)&&(n=r);return n}function pt$1(t){let e=[];const n=t.getChildren().filter(It$2);for(let t=0;t<n.length;t++){const r=n[t],i=r.getFirstChild();Rt$1(i)?e=e.concat(pt$1(i)):e.push(r);}return e}function mt$1(t){return It$2(t)&&Rt$1(t.getFirstChild())}function _t$1(t,e){return It$2(t)&&(0===e.length||1===e.length&&t.is(e[0])&&0===t.getChildrenSize())}function yt$1(t){const e=Lr();if(null!==e){let n=e.getNodes();if(cr(e)){const[r]=e.getStartEndPoints(),i=r.getNode(),l=i.getParent();if(Kl(i)){const t=i.getFirstChild();if(t)n=t.selectStart().getNodes();else {const t=ts();i.append(t),n=t.select().getNodes();}}else if(_t$1(i,n)){const e=$t$1(t);if(Kl(l)){i.replace(e);const t=Ot$1();Pi(i)&&(t.setFormat(i.getFormatType()),t.setIndent(i.getIndent())),e.append(t);}else if(It$2(i)){const t=i.getParentOrThrow();Ct$2(e,t.getChildren()),t.replace(e);}return}}const i=new Set;for(let e=0;e<n.length;e++){const r=n[e];if(Pi(r)&&r.isEmpty()&&!It$2(r)&&!i.has(r.getKey())){kt$1(r,t);continue}let o=Rs(r)?r.getParent():It$2(r)&&r.isEmpty()?r:null;for(;null!=o;){const e=o.getKey();if(Rt$1(o)){if(!i.has(e)){const n=$t$1(t);Ct$2(n,o.getChildren()),o.replace(n),i.add(e);}break}{const n=o.getParent();if(Kl(n)&&!i.has(e)){i.add(e),kt$1(o,t);break}o=n;}}}}}function Ct$2(t,e){t.splice(t.getChildrenSize(),0,e);}function kt$1(t,e){if(Rt$1(t))return t;const i=t.getPreviousSibling(),s=t.getNextSibling(),o=Ot$1();let l;if(Ct$2(o,t.getChildren()),Rt$1(i)&&e===i.getListType())i.append(o),Rt$1(s)&&e===s.getListType()&&(Ct$2(i,s.getChildren()),s.remove()),l=i;else if(Rt$1(s)&&e===s.getListType())s.getFirstChildOrThrow().insertBefore(o),l=s;else {const n=$t$1(e);n.append(o),t.replace(n),l=n;}o.setFormat(t.getFormatType()),o.setIndent(t.getIndent());const c=Lr();return cr(c)&&(l.getKey()===c.anchor.key&&c.anchor.set(o.getKey(),c.anchor.offset,"element"),l.getKey()===c.focus.key&&c.focus.set(o.getKey(),c.focus.offset,"element")),t.remove(),l}function bt$1(t,e){const n=t.getLastChild(),r=e.getFirstChild();n&&r&&mt$1(n)&&mt$1(r)&&(bt$1(n.getFirstChild(),r.getFirstChild()),r.remove());const i=e.getChildren();i.length>0&&t.append(...i),e.remove();}function St$1(){const e=Lr();if(cr(e)){const n=new Set,r=e.getNodes(),i=e.anchor.getNode();if(_t$1(i,r))n.add(dt$2(i));else for(let e=0;e<r.length;e++){const i=r[e];if(Rs(i)){const e=_t$3(i,Pt$2);null!=e&&n.add(dt$2(e));}}for(const t of n){let n=t;const r=pt$1(t);for(const t of r){const r=ts().setTextStyle(e.style).setTextFormat(e.format);Ct$2(r,t.getChildren()),n.insertAfter(r),n=r,t.__key===e.anchor.key&&Qa(e.anchor,su(La(r,"next"))),t.__key===e.focus.key&&Qa(e.focus,su(La(r,"next"))),t.remove();}t.remove();}}}function vt$1(t){const e="check"!==t.getListType();let n=t.getStart();for(const r of t.getChildren())It$2(r)&&(r.getValue()!==n&&r.setValue(n),e&&null!=r.getLatest().__checked&&r.setChecked(void 0),Rt$1(r.getFirstChild())||n++);}function Tt$2(t){const e=new Set;if(mt$1(t)||e.has(t.getKey()))return;const n=t.getParent(),r=t.getNextSibling(),i=t.getPreviousSibling();if(mt$1(r)&&mt$1(i)){const n=i.getFirstChild();if(Rt$1(n)){n.append(t);const i=r.getFirstChild();if(Rt$1(i)){Ct$2(n,i.getChildren()),r.remove(),e.add(r.getKey());}}}else if(mt$1(r)){const e=r.getFirstChild();if(Rt$1(e)){const n=e.getFirstChild();null!==n&&n.insertBefore(t);}}else if(mt$1(i)){const e=i.getFirstChild();Rt$1(e)&&e.append(t);}else if(Rt$1(n)){const e=zl(t),s=zl(n);e.append(s),s.append(t),i?i.insertAfter(e):r?r.insertBefore(e):n.append(e);}}function xt$1(t){if(mt$1(t))return;const e=t.getParent(),n=e?e.getParent():void 0;if(Rt$1(n?n.getParent():void 0)&&It$2(n)&&Rt$1(e)){const r=e?e.getFirstChild():void 0,i=e?e.getLastChild():void 0;if(t.is(r))n.insertBefore(t),e.isEmpty()&&n.remove();else if(t.is(i))n.insertAfter(t),e.isEmpty()&&n.remove();else {const r=zl(t),i=zl(e);r.append(i),t.getPreviousSiblings().forEach(t=>i.append(t));const s=zl(t),o=zl(e);s.append(o),Ct$2(o,t.getNextSiblings()),n.insertBefore(r),n.insertAfter(s),n.replace(t);}}}function Nt$1(t=false){const e=Lr();if(!cr(e)||!e.isCollapsed())return  false;const c=e.anchor.getNode();let a=null;if(It$2(c)&&0===c.getChildrenSize())a=c;else if(Xo(c)){const t=c.getParent();It$2(t)&&t.getChildren().every(t=>Xo(t)&&""===t.getTextContent().trim())&&(a=t);}if(null===a)return  false;const u=dt$2(a),g=a.getParent();Rt$1(g)||ht$2(40);const h=g.getParent();let f;if(Kl(h))f=ts(),u.insertAfter(f);else {if(!It$2(h))return  false;f=zl(h),h.insertAfter(f);}f.setTextStyle(e.style).setTextFormat(e.format).select();const d=a.getNextSiblings();if(d.length>0){const e=t?function(t,e){return t.getStart()+e.getIndexWithinParent()}(g,a):1,n=zl(g).setStart(e);if(It$2(f)){const t=zl(f);t.append(n),f.insertAfter(t);}else f.insertAfter(n);n.append(...d);}return function(t){let e=t;for(;null==e.getNextSibling()&&null==e.getPreviousSibling();){const t=e.getParent();if(null==t||!It$2(t)&&!Rt$1(t))break;e=t;}e.remove();}(a),true}let Pt$2 = class Pt extends Di{__value;__checked;$config(){return this.config("listitem",{$transform:t=>{const e=t.getParent();if(Rt$1(e))"check"!==e.getListType()&&null!=t.getChecked()&&t.setChecked(void 0);else if(e){const n=t.createParentElementNode();Rt$1(n)||ht$2(340);const r=[t];for(const e of ["previous","next"]){r.reverse();for(const{origin:n}of Da(t,e)){if(!It$2(n))break;r.push(n);}}t.insertBefore(n),n.splice(0,0,r),Kl(e)||(_u(n,nu(Da(n,"next")),{$shouldSplit:()=>false,removeEmptyDestination:true}),e.isEmpty()&&e.isAttached()&&e.remove());}},extends:Di,importDOM:uo$1({li:()=>({conversion:Ft$1,priority:0})})})}constructor(t=1,e=void 0,n){super(n),this.__value=void 0===t?1:t,this.__checked=e;}afterCloneFrom(t){super.afterCloneFrom(t),this.__value=t.__value,this.__checked=t.__checked;}createDOM(t){const e=Xl().createElement("li");return this.updateListItemDOM(null,e,t),e}updateListItemDOM(t,e,n){!function(t,e){const n=e.getParent();!Rt$1(n)||"check"!==n.getListType()||Rt$1(e.getFirstChild())?(t.removeAttribute("role"),t.removeAttribute("tabIndex"),t.removeAttribute("aria-checked")):(t.setAttribute("role","checkbox"),t.setAttribute("tabIndex","-1"),t.setAttribute("aria-checked",e.getChecked()?"true":"false"));}(e,this),e.value=this.__value,function(t,e,n){const r=e.list;if(!r)return;const i=r.listitem,s=r.nested&&r.nested.listitem,o=n.getParent(),l=Rt$1(o)&&"check"===o.getListType(),c=n.getChecked(),a=n.getChildren().some(t=>Rt$1(t)),u=[];void 0!==r.listitemChecked&&u.push(r.listitemChecked);void 0!==r.listitemUnchecked&&u.push(r.listitemUnchecked);void 0!==s&&u.push(...Su(s));u.length>0&&Tu(t,...u);const g=[];void 0!==i&&g.push(...Su(i));if(l){const t=c?r.listitemChecked:r.listitemUnchecked;void 0!==t&&g.push(t);} void 0!==s&&a&&g.push(...Su(s));g.length>0&&vu(t,...g);}(e,n.theme,this);const r=t?t.__style:"",i=this.__style;r!==i&&Po(e.style,i,r),function(t,e,n){const r=e.__textStyle,i=n?n.__textStyle:"";if(null!==n&&i===r)return;const s=Mo(r);for(const e in s)t.style.setProperty(`--listitem-marker-${e}`,s[e]);if(""!==i)for(const e in Mo(i))e in s||t.style.removeProperty(`--listitem-marker-${e}`);}(e,this,t);}updateDOM(t,e,n){const r=e;return this.updateListItemDOM(t,r,n),false}updateFromJSON(t){return super.updateFromJSON(t).setValue(t.value).setChecked(t.checked)}exportDOM(t){const e=this.createDOM(t._config),n=this.getFormatType();n&&(e.style.textAlign=n);const r=this.getDirection();return r&&(e.dir=r),mt$1(this)?{after(t){if(dc(t)){const e=t.previousElementSibling;if(dc(e)&&"LI"===e.nodeName){for(;t.firstChild;)e.append(t.firstChild);t.remove();}}return t},element:e}:{element:e}}exportJSON(){return {...super.exportJSON(),checked:this.getChecked(),value:this.getValue()}}append(...t){for(let e=0;e<t.length;e++){const n=t[e];if(Pi(n)&&this.canMergeWith(n)){const t=n.getChildren();this.append(...t),n.remove();}else super.append(n);}return this}replace(t,e){if(It$2(t))return super.replace(t);this.setIndent(0);const i=this.getParentOrThrow();if(!Rt$1(i))return t;if(i.__first===this.getKey())i.insertBefore(t);else if(i.__last===this.getKey())i.insertAfter(t);else {const e=zl(i);let n=this.getNextSibling();for(;n;){const t=n;n=n.getNextSibling(),e.append(t);}i.insertAfter(t),t.insertAfter(e);}const s=this.__key;let o=0;if(e&&(Pi(t)||ht$2(139),o=t.getChildrenSize(),t.splice(o,0,this.getChildren())),e&&Pi(t)){const e=Lr();if(cr(e))for(const n of e.getStartEndPoints())n.key===s&&"element"===n.type&&n.set(t.getKey(),o+n.offset,"element");}return this.remove(),0===i.getChildrenSize()&&i.remove(),t}insertAfter(t,e=true){const n=this.getParentOrThrow();if(Rt$1(n)||ht$2(39),It$2(t))return super.insertAfter(t,e);const r=this.getNextSiblings();if(n.insertAfter(t,e),0!==r.length){const i=zl(n);r.forEach(t=>i.append(t)),t.insertAfter(i,e);}return t}remove(t){const e=this.getPreviousSibling(),n=this.getNextSibling();super.remove(t),e&&n&&mt$1(e)&&mt$1(n)&&(bt$1(e.getFirstChild(),n.getFirstChild()),n.remove());}resetOnCopyNodeFrom(t){super.resetOnCopyNodeFrom(t),t.getChecked()&&this.setChecked(false);}insertNewAfter(t,e=true){const n=zl(this);return this.insertAfter(n,e),n}collapseAtStart(t){if(mt$1(this))return  false;const e=this.getParentOrThrow();if(It$2(e.getParentOrThrow()))return xt$1(this),true;const n=ts().append(...this.getChildren()),r=this.getNextSiblings();if(r.length>0){const t=zl(e);t.append(...r),e.insertAfter(t);}return e.insertAfter(n),this.remove(),0===e.getChildrenSize()&&e.remove(),n.selectStart(),true}getValue(){return this.getLatest().__value}setValue(t){const e=this.getWritable();return e.__value=t,e}getChecked(){const t=this.getLatest();let e;const n=this.getParent();return Rt$1(n)&&(e=n.getListType()),"check"===e?Boolean(t.__checked):void 0}setChecked(t){const e=this.getWritable();return e.__checked=t,e}toggleChecked(){const t=this.getWritable();return t.setChecked(!t.__checked)}getIndent(){const t=this.getParent();if(null===t||!this.isAttached())return this.getLatest().__indent;let e=t.getParentOrThrow(),n=0;for(;It$2(e);)e=e.getParentOrThrow().getParentOrThrow(),n++;return n}setIndent(t){"number"!=typeof t&&ht$2(117),(t=Math.floor(t))>=0||ht$2(199);let e=this.getIndent();for(;e!==t;)e<t?(Tt$2(this),e++):(xt$1(this),e--);return this}canInsertAfter(t){return It$2(t)}canReplaceWith(t){return It$2(t)}canMergeWith(t){return It$2(t)||es(t)}extractWithChild(t,e){if(!cr(e))return  false;const n=e.anchor.getNode(),i=e.focus.getNode();return this.isParentOf(n)&&this.isParentOf(i)&&this.getTextContent().length===e.getTextContent().length}isParentRequired(){return  true}createParentElementNode(){return $t$1("bullet")}canMergeWhenEmpty(){return  true}};function Ft$1(t){if(t.classList.contains("task-list-item"))for(const e of t.children)if("INPUT"===e.tagName)return Lt$1(e);if(t.classList.contains("joplin-checkbox"))for(const e of t.children)if(e.classList.contains("checkbox-wrapper")&&e.children.length>0&&"INPUT"===e.children[0].tagName)return Lt$1(e.children[0]);const e=t.getAttribute("aria-checked"),n=Ot$1("true"===e||"false"!==e&&void 0);return Fc(n,t),{after:At$2.bind(null,n),node:Pc(n,t)}}function Lt$1(t){if(!("checkbox"===t.getAttribute("type")))return {node:null};const e=Ot$1(t.hasAttribute("checked"));return {after:At$2.bind(null,e),node:e}}function At$2(t,e){const n=e[0];return 1===e.length&&es(n)&&!t.getFormatType()&&n.getFormatType()?(t.setFormat(n.getFormatType()),n.getChildren()):e}function Ot$1(t){return Bl(new Pt$2(void 0,t))}function It$2(t){return t instanceof Pt$2}let Et$1 = class Et extends Di{__tag;__start;__listType;$config(){return this.config("list",{$transform:t=>{!function(t){const e=t.getNextSibling();Rt$1(e)&&t.getListType()===e.getListType()&&bt$1(t,e);}(t),vt$1(t);},extends:Di,importDOM:uo$1({ol:()=>({conversion:wt$1,priority:0}),ul:()=>({conversion:wt$1,priority:0})})})}constructor(t="number",e=1,n){super(n);const r=Mt$1[t]||t;this.__listType=r,this.__tag="number"===r?"ol":"ul",this.__start=e;}afterCloneFrom(t){super.afterCloneFrom(t),this.__listType=t.__listType,this.__tag=t.__tag,this.__start=t.__start;}getTag(){return this.getLatest().__tag}setListType(t){const e=this.getWritable();return e.__listType=t,e.__tag="number"===t?"ol":"ul",e}getListType(){return this.getLatest().__listType}getStart(){return this.getLatest().__start}setStart(t){const e=this.getWritable();return e.__start=t,e}createDOM(t,e){const n=this.__tag,r=Xl().createElement(n);return 1!==this.__start&&r.setAttribute("start",String(this.__start)),r.__lexicalListType=this.__listType,Dt$2(r,t.theme,this),r}updateDOM(t,e,n){return t.__tag!==this.__tag||t.__listType!==this.__listType||(Dt$2(e,n.theme,this),t.__start!==this.__start&&e.setAttribute("start",String(this.__start)),false)}updateFromJSON(t){return super.updateFromJSON(t).setListType(t.listType).setStart(t.start)}exportDOM(t){const e=this.createDOM(t._config,t);return dc(e)&&(1!==this.__start&&e.setAttribute("start",String(this.__start)),"check"===this.__listType&&e.setAttribute("__lexicalListType","check")),{element:e}}exportJSON(){return {...super.exportJSON(),listType:this.getListType(),start:this.getStart(),tag:this.getTag()}}canBeEmpty(){return  false}canIndent(){return  false}splice(t,e,n){let r=n;for(let t=0;t<n.length;t++){const e=n[t];It$2(e)||(r===n&&(r=[...n]),r[t]=this.createListItemNode().append(!Pi(e)||Rt$1(e)||e.isInline()?e:Go(e.getTextContent())));}return super.splice(t,e,r)}extractWithChild(t){return It$2(t)}createListItemNode(){return Ot$1()}};function Dt$2(t,e,n){const r=[],i=[],s=e.list;if(void 0!==s){const t=s[`${n.__tag}Depth`]||[],e=ft$2(n)-1,o=e%t.length,l=t[o],c=s[n.__tag];let a;const u=s.nested,g=s.checklist;if(void 0!==u&&u.list&&(a=u.list),void 0!==c&&r.push(c),void 0!==g&&"check"===n.__listType&&r.push(g),void 0!==l){r.push(...Su(l));for(let e=0;e<t.length;e++)e!==o&&i.push(n.__tag+e);}if(void 0!==a){const t=Su(a);e>1?r.push(...t):i.push(...t);}}i.length>0&&Tu(t,...i),r.length>0&&vu(t,...r);}function wt$1(t){let e;if(function(t){return dc(t)&&"ol"===t.nodeName.toLowerCase()}(t)){const n=t.start;e=$t$1("number",n);}else e=function(t){if("check"===t.getAttribute("__lexicallisttype")||t.classList.contains("contains-task-list")||"1"===t.getAttribute("data-is-checklist"))return  true;for(const e of t.childNodes)if(dc(e)&&e.hasAttribute("aria-checked"))return  true;return  false}(t)?$t$1("check"):$t$1("bullet");return Pc(e,t),{after:t=>function(t,e){const n=e.createListItemNode.bind(e),r=[];for(let e=0;e<t.length;e++){const i=t[e];if(It$2(i)){r.push(i);const t=i.getChildren();t.length>1&&t.forEach(t=>{Rt$1(t)&&r.push(n().append(t));});}else r.push(n().append(i));}return r}(t,e),node:e}}const Mt$1={ol:"number",ul:"bullet"};function $t$1(t="number",e=1){return Bl(new Et$1(t,e))}function Rt$1(t){return t instanceof Et$1}function qt$1(t){const e=[];for(const n of t)if(It$2(n)){e.push(n);const t=n.getChildren();if(t.length>1)for(const n of t)Rt$1(n)&&e.push(Ot$1().append(n));}else e.push(Ot$1().append(n));return e}const zt$2=/* @__PURE__ */Se$1({$import:(t,e)=>{let n;var r;return he$1(e,"ol")?n=$t$1("number",e.start):n=(r=e).matches('[__lexicallisttype="check"], .contains-task-list, [data-is-checklist="1"]')||null!==r.querySelector(":scope > [aria-checked]")?$t$1("check"):$t$1("bullet"),Pc(n,e),[n.splice(0,0,Fe$1(qt$1(t.$importChildren(e)),e))]},match:Cn$1.tag("ol","ul"),name:"@lexical/list/list"});function jt$2(t,e){if(1!==e.length)return e;const n=e[0];return es(n)&&!t.getFormatType()&&n.getFormatType()?(t.setFormat(n.getFormatType()),n.getChildren()):e}function Ht$2(t){const e=t=>We(t)&&!Rt$1(t);if(!t.some(e))return t;const n=[];let r=[];const i=()=>{r.length>0&&(n.push(r),r=[]);};for(const s of t)e(s)?(i(),n.push(Pi(s)?s.getChildren():[s])):r.push(s);i();const s=[];for(const t of n)s.length>0&&s.push(Vi()),s.push(...t);return s}const Xt$2=/* @__PURE__ */Se$1({$import:(t,e)=>{const n=e.getAttribute("aria-checked"),r=Ot$1("true"===n||"false"!==n&&void 0);return Fc(r,e),Pc(r,e),[r.splice(0,0,Ht$2(jt$2(r,t.$importChildren(e))))]},match:Cn$1.tag("li"),name:"@lexical/list/li"});function Gt(t,e,n){const r=he$1(n,"input")?n:n.querySelector('input[type="checkbox"]');if(!r||"checkbox"!==r.getAttribute("type"))return [];const i=Ot$1(r.hasAttribute("checked"));return Fc(i,e),Pc(i,e),[i.splice(0,0,Ht$2(jt$2(i,t.$importChildren(e))))]}[/* @__PURE__ */Se$1({$import:(t,e,n)=>{const r=e.querySelector(':scope > input[type="checkbox"]');return r?Gt(t,e,r):n()},match:Cn$1.tag("li").classAll("task-list-item"),name:"@lexical/list/li-task-list-item"}),/* @__PURE__ */Se$1({$import:(t,e,n)=>{const r=e.querySelector(":scope > .checkbox-wrapper");if(!r)return n();const i=r.querySelector(':scope > input[type="checkbox"]');return i?Gt(t,e,i):n()},match:Cn$1.tag("li").classAll("joplin-checkbox"),name:"@lexical/list/li-joplin-checkbox"}),zt$2,Xt$2];const Zt$2=/* @__PURE__ */Fe$3("UPDATE_LIST_START_COMMAND"),te$1=/* @__PURE__ */Fe$3("INSERT_UNORDERED_LIST_COMMAND"),ee$1=/* @__PURE__ */Fe$3("INSERT_ORDERED_LIST_COMMAND"),ne$1=/* @__PURE__ */Fe$3("REMOVE_LIST_COMMAND");function re$1(t,e){return ku(t.registerCommand(ee$1,()=>(yt$1("number"),true),rs),t.registerCommand(Zt$2,t=>{const{listNodeKey:e,newStart:n}=t,r=Vs(e);return !!Rt$1(r)&&("number"===r.getListType()&&(r.setStart(n),vt$1(r)),true)},rs),t.registerCommand(te$1,()=>(yt$1("bullet"),true),rs),t.registerCommand(ne$1,()=>(St$1(),true),rs),t.registerCommand(He$3,()=>Nt$1(false),rs),t.registerCommand(un$2,t=>{if(function(){const t=Lr();if(!cr(t)||!t.isCollapsed()||0!==t.anchor.offset)return  false;const e=t.anchor.getNode(),i=Jc(e,It$2);if(!It$2(i))return  false;const s=i.getFirstDescendant();if(null===s)return  false;if(!i.is(e)&&!s.is(e))return  false;const l=i.getParent();if(!Rt$1(l)||!i.is(l.getFirstChild()))return  false;const c=l.getPreviousSibling();if(!Li(c)||c.isIsolated()||!c.isKeyboardSelectable()&&c.isInline())return  false;const a=ts().append(...i.getChildren());l.insertBefore(a),i.remove(),l.isEmpty()&&l.remove();return a.selectStart(),true}())return t.preventDefault(),true;const e=Lr();if(!cr(e)||!e.isCollapsed())return  false;const{anchor:i}=e;if(0!==i.offset)return  false;let s=i.getNode();for(;!It$2(s);){if(null!==s.getPreviousSibling())return  false;const t=s.getParent();if(null===t)return  false;s=t;}return !(!It$2(s)||!s.collapseAtStart(e))&&(t.preventDefault(),true)},cs),t.registerNodeTransform(Pt$2,t=>{const e=t.getFirstChild();if(e){if(Xo(e)){const n=e.getStyle(),r=e.getFormat();t.getTextStyle()!==n&&t.setTextStyle(n),t.getTextFormat()!==r&&t.setTextFormat(r);}}else {const e=Lr();cr(e)&&(e.style!==t.getTextStyle()||e.format!==t.getTextFormat())&&e.isCollapsed()&&t.is(e.anchor.getNode())&&t.setTextStyle(e.style).setTextFormat(e.format);}}),t.registerNodeTransform(Wo,t=>{const e=t.getParent();if(It$2(e)&&t.is(e.getFirstChild())){const n=t.getStyle(),r=t.getFormat();n===e.getTextStyle()&&r===e.getTextFormat()||e.setTextStyle(n).setTextFormat(r);}}))}

	/**
	 * Copyright (c) Meta Platforms, Inc. and affiliates.
	 *
	 * This source code is licensed under the MIT license found in the
	 * LICENSE file in the root directory of this source tree.
	 *
	 */

	const j=new Set(["http:","https:","mailto:","sms:","tel:"]);let Z$1 = class Z extends Di{__url;__target;__rel;__title;static getType(){return "link"}static clone(t){return new Z(t.__url,{rel:t.__rel,target:t.__target,title:t.__title},t.__key)}constructor(t="",e={},n){super(n);const{target:r=null,rel:i=null,title:l=null}=e;this.__url=t,this.__target=r,this.__rel=i,this.__title=l;}afterCloneFrom(t){super.afterCloneFrom(t),this.__url=t.__url,this.__rel=t.__rel,this.__target=t.__target,this.__title=t.__title;}createDOM(t){const e=Xl().createElement("a");return this.updateLinkDOM(null,e,t),vu(e,t.theme.link),e}updateLinkDOM(t,e,n){if(ac(e)){t&&t.__url===this.__url||(e.href=this.sanitizeUrl(this.__url));for(const n of ["target","rel","title"]){const r=`__${n}`,i=this[r];t&&t[r]===i||(i?e[n]=i:e.removeAttribute(n));}}}updateDOM(t,e,n){return this.updateLinkDOM(t,e,n),false}static importDOM(){return {a:t=>({conversion:Q$1,priority:1})}}static importJSON(t){return V$1().updateFromJSON(t)}updateFromJSON(t){return super.updateFromJSON(t).setURL(t.url).setRel(t.rel||null).setTarget(t.target||null).setTitle(t.title||null)}sanitizeUrl(t){const e=t;t=st$2(t);try{const e=new URL(st$2(t));if(!j.has(e.protocol))return "about:blank"}catch(t){const n=e.replace(/[\u0000-\u001F\u007F\s]/g,"").match(/^([a-z][a-z0-9+.-]*):/i);if(null!=n&&!j.has(`${n[1].toLowerCase()}:`))return "about:blank"}return t}exportJSON(){return {...super.exportJSON(),rel:this.getRel(),target:this.getTarget(),title:this.getTitle(),url:this.getURL()}}getURL(){return this.getLatest().__url}setURL(t){const e=this.getWritable();return e.__url=t,e}getTarget(){return this.getLatest().__target}setTarget(t){const e=this.getWritable();return e.__target=t,e}getRel(){return this.getLatest().__rel}setRel(t){const e=this.getWritable();return e.__rel=t,e}getTitle(){return this.getLatest().__title}setTitle(t){const e=this.getWritable();return e.__title=t,e}insertNewAfter(t,e=true){const n=zl(this);return this.insertAfter(n,e),n}canInsertTextBefore(){return  false}canInsertTextAfter(){return  false}canBeEmpty(){return  false}isInline(){return  true}extractWithChild(t,e,n){if(!cr(e))return  false;const r=e.anchor.getNode(),i=e.focus.getNode();return (this.is(r)||this.isParentOf(r))&&(this.is(i)||this.isParentOf(i))&&e.getTextContent().length>0}isEmailURI(){return this.__url.startsWith("mailto:")}isWebSiteURI(){return this.__url.startsWith("https://")||this.__url.startsWith("http://")}shouldMergeAdjacentLink(t){return this.getType()===t.getType()&&this.__url===t.__url&&this.__target===t.__target&&this.__rel===t.__rel&&this.__title===t.__title}};function H(t){const e=Xa(t,"next");return [e,e.getFlipped()]}function G(t,e){for(const n of e)if(n.origin.isAttached()){const e=su(n);return void Qa(t,e)}}function q(t){const e=Lr();let n=null,r=null;function i(){cr(e)&&(G(e.anchor,n),G(e.focus,r),It$5(e));}cr(e)&&(n=H(e.anchor),r=H(e.focus));let l=false;for(const e of La(t,"next")){const n=e.origin;if(Pi(n)&&!n.isInline()){const r=n.getChildren();if(r.length>0){const e=zl(t);e.append(...r),n.append(e),l=true;}_u(n,nu(e),{$shouldSplit:()=>false});}}function u(t,e,n){const[r,i]=t,l=t=>wa(t)&&t.origin.is(e);if(!l(r)&&!l(i))return t;const s=su(La(n,"next"));return [s,s.getFlipped()]}if(t.isAttached()){const e=t.getPreviousSibling();if(X$1(e)&&e.shouldMergeAdjacentLink(t))return n&&(n=u(n,e,t)),r&&(r=u(r,e,t)),e.append(...t.getChildren()),t.remove(),void i();const s=t.getNextSibling();X$1(s)&&t.shouldMergeAdjacentLink(s)&&(n&&(n=u(n,t,s)),r&&(r=u(r,t,s)),t.append(...s.getChildren()),s.remove(),l=true);}if(l){if(!t.canBeEmpty()&&t.isEmpty()){const e=t.getParent();t.remove(),e&&e.isEmpty()&&e.remove();}i();}}function Q$1(t){let e=null;if(ac(t)){const n=t.textContent;(null!==n&&""!==n||t.children.length>0)&&(e=V$1(t.getAttribute("href")||"",{rel:t.getAttribute("rel"),target:t.getAttribute("target"),title:t.getAttribute("title")}));}return {node:e}}function V$1(t="",e){return Bl(new Z$1(t,e))}function X$1(t){return t instanceof Z$1}let Y$1 = class Y extends Z$1{__isUnlinked;constructor(t="",e={},n){super(t,e,n),this.__isUnlinked=void 0!==e.isUnlinked&&null!==e.isUnlinked&&e.isUnlinked;}afterCloneFrom(t){super.afterCloneFrom(t),this.__isUnlinked=t.__isUnlinked;}static getType(){return "autolink"}static clone(t){return new Y(t.__url,{isUnlinked:t.__isUnlinked,rel:t.__rel,target:t.__target,title:t.__title},t.__key)}shouldMergeAdjacentLink(t){return  false}getIsUnlinked(){return this.__isUnlinked}setIsUnlinked(t){const e=this.getWritable();return e.__isUnlinked=t,e}createDOM(t){return this.__isUnlinked?Xl().createElement("span"):super.createDOM(t)}updateDOM(t,e,n){return super.updateDOM(t,e,n)||t.__isUnlinked!==this.__isUnlinked}static importJSON(t){return tt$2().updateFromJSON(t)}updateFromJSON(t){return super.updateFromJSON(t).setIsUnlinked(t.isUnlinked||false)}static importDOM(){return null}exportJSON(){return {...super.exportJSON(),isUnlinked:this.__isUnlinked}}insertNewAfter(t,e=true){const n=tt$2(this.__url,{isUnlinked:this.__isUnlinked,rel:this.__rel,target:this.__target,title:this.__title});return this.insertAfter(n,e),n}};function tt$2(t="",e){return Bl(new Y$1(t,e))}function et$1(t){return t instanceof Y$1}const nt$2=/* @__PURE__ */Fe$3("TOGGLE_LINK_COMMAND");function rt$2(t,e){if("element"===t.type){const n=t.getNode();Pi(n)||function(t,...e){const n=new URL("https://lexical.dev/docs/error"),r=new URLSearchParams;r.append("code",t);for(const t of e)r.append("v",t);throw n.search=r.toString(),Error(`Minified Lexical error #${t}; visit ${n.toString()} for the full message or use the non-minified dev environment for full errors and additional helpful warnings.`)}(252);return n.getChildren()[t.offset+e]||null}return null}function it$2(t,e={}){let n;if(t&&"object"==typeof t){const{url:r,...i}=t;n=r,e={...i,...e};}else n=t;const{target:r,title:i}=e,l=void 0===e.rel?"noreferrer":e.rel,u=Lr();if(null===u||!cr(u)&&!ur(u))return;if(ur(u)){const t=u.getNodes();if(0===t.length)return;return void t.forEach(t=>{if(null===n){const e=Jc(t,t=>!et$1(t)&&X$1(t));e&&(e.insertBefore(t),0===e.getChildren().length&&e.remove());}else {const e=Jc(t,t=>!et$1(t)&&X$1(t));if(e)e.setURL(n),void 0!==r&&e.setTarget(r),void 0!==l&&e.setRel(l);else {const e=V$1(n,{rel:l,target:r});t.insertBefore(e),e.append(t);}}})}if(u.isCollapsed()&&null===n)for(const t of u.getNodes()){const e=Jc(t,t=>!et$1(t)&&X$1(t));return void(null!==e&&(e.getParentOrThrow().splice(e.getIndexWithinParent(),0,e.getChildren()),e.remove()))}const a=u.extract();if(null===n){const t=new Set;return void a.forEach(e=>{const n=Jc(e,t=>!et$1(t)&&X$1(t));if(null!==n){const e=n.getKey();if(t.has(e))return;!function(t,e){const n=new Set(e.filter(e=>t.isParentOf(e)).map(t=>t.getKey())),r=t.getChildren(),i=r=>n.has(r.getKey())||Pi(r)&&e.some(e=>t.isParentOf(e)&&r.isParentOf(e)),l=r.filter(i);if(l.length===r.length)return r.forEach(e=>t.insertBefore(e)),void t.remove();const o=r.findIndex(i),u=r.findLastIndex(i),a=0===o,c=u===r.length-1;if(a)l.forEach(e=>t.insertBefore(e));else if(c)for(let e=l.length-1;e>=0;e--)t.insertAfter(l[e]);else {for(let e=l.length-1;e>=0;e--)t.insertAfter(l[e]);const e=r.slice(u+1);if(e.length>0){const n=zl(t);l[l.length-1].insertAfter(n),e.forEach(t=>n.append(t));}}}(n,a),t.add(e);}})}const p=new Set,m=t=>{p.has(t.getKey())||(p.add(t.getKey()),t.setURL(n),void 0!==r&&t.setTarget(r),void 0!==l&&t.setRel(l),void 0!==i&&t.setTitle(i));};if(1===a.length){const t=a[0],e=Jc(t,X$1);if(null!==e)return m(e)}!function(t){const e=Lr();if(!cr(e))return t();const n=It$5(e),r=n.isBackward(),i=rt$2(n.anchor,r?-1:0),l=rt$2(n.focus,r?0:-1);t();if(i||l){const t=Lr();if(cr(t)){const e=t.clone();if(i){const t=i.getParent();t&&e.anchor.set(t.getKey(),i.getIndexWithinParent()+(r?1:0),"element");}if(l){const t=l.getParent();t&&e.focus.set(t.getKey(),l.getIndexWithinParent()+(r?0:1),"element");}el(It$5(e));}}}(()=>{let t=null;for(const e of a){if(!e.isAttached())continue;const s=Jc(e,X$1);if(s){m(s);continue}if(Pi(e)){if(!e.isInline())continue;if(X$1(e)){if(!(et$1(e)||null!==t&&t.getParentOrThrow().isParentOf(e))){m(e),t=e;continue}for(const t of e.getChildren())e.insertBefore(t);e.remove();continue}}const o=e.getPreviousSibling();X$1(o)&&o.is(t)?o.append(e):(t=V$1(n,{rel:l,target:r,title:i}),e.insertAfter(t),t.append(e));}});}const lt$2=/^\+?[0-9\s()-]{5,}$/;function st$2(t){return t.match(/^[a-z][a-z0-9+.-]*:/i)||t.match(/^[/#.]/)?t:t.includes("@")?`mailto:${t}`:lt$2.test(t)?`tel:${t}`:`https://${t}`}[/* @__PURE__ */Se$1({$import:(t,e)=>{if(!e.textContent&&0===e.children.length)return [];const n=e.getAttribute("href")||"",r={rel:e.getAttribute("rel"),target:e.getAttribute("target"),title:e.getAttribute("title")};return Re(t.$importChildren(e),()=>V$1(n,r))},match:Cn$1.tag("a"),name:"@lexical/link/a"})];function ut$2(t,n){return ku(t.registerNodeTransform(Z$1,q),t.registerCommand(nt$2,t=>{const e=n.validateUrl.peek(),r=n.attributes.peek();if(null===t)return it$2(null),true;if("string"==typeof t)return !(void 0!==e&&!e(t))&&(it$2(t,r),true);{const{url:e,target:n,rel:i,title:l}=t;return it$2(e,{...r,rel:i,target:n,title:l}),true}},os),ce$2(()=>{const e=n.validateUrl.value;if(!e)return;const r=n.attributes.value;return t.registerCommand(je$2,n=>{const i=Lr();if(!cr(i)||i.isCollapsed()||!Mt$4(n,ClipboardEvent))return  false;if(null===n.clipboardData)return  false;const l=n.clipboardData.getData("text");if(!e(l))return  false;return !i.getNodes().some(t=>Pi(t)||Xo(t)&&!t.isSimpleText())&&(t.dispatchCommand(nt$2,{...r,url:l}),n.preventDefault(),true)},rs)}))}

	/**
	 * Copyright (c) Meta Platforms, Inc. and affiliates.
	 *
	 * This source code is licensed under the MIT license found in the
	 * LICENSE file in the root directory of this source tree.
	 *
	 */

	function le$1(e,t){let n=e;for(let r=Da(e,t);r&&(Le(r.origin)||er(r.origin));r=r.getAdjacentCaret())n=r.origin;return n}function ue$1(e){return le$1(e,"previous")}function xe$1(e,t){for(const n of e.childNodes){if(dc(n)&&n.tagName===t)return  true;if(xe$1(n,t))return  true}return  false}const _e="data-language",Se="data-highlight-language",ye="data-theme";class ve extends Di{__language;__theme;__isSyntaxHighlightSupported;static getType(){return "code"}static clone(e){return new ve(e.__language,e.__key)}constructor(e,t){super(t),this.__language=e||void 0,this.__isSyntaxHighlightSupported=false,this.__theme=void 0;}afterCloneFrom(e){super.afterCloneFrom(e),this.__language=e.__language,this.__theme=e.__theme,this.__isSyntaxHighlightSupported=e.__isSyntaxHighlightSupported;}createDOM(e){const t=Xl().createElement("code");vu(t,e.theme.code),t.setAttribute("spellcheck","false");const n=this.getLanguage();n&&(t.setAttribute(_e,n),this.getIsSyntaxHighlightSupported()&&t.setAttribute(Se,n));const r=this.getTheme();r&&t.setAttribute(ye,r);const i=this.getStyle();return i&&Po(t.style,i),t}updateDOM(e,t,n){const r=this.__language,i=e.__language;r?r!==i&&t.setAttribute(_e,r):i&&t.removeAttribute(_e);const o=this.__isSyntaxHighlightSupported;e.__isSyntaxHighlightSupported&&i?o&&r?r!==i&&t.setAttribute(Se,r):t.removeAttribute(Se):o&&r&&t.setAttribute(Se,r);const s=this.__theme,l=e.__theme;s?s!==l&&t.setAttribute(ye,s):l&&t.removeAttribute(ye);const u=this.__style,c=e.__style;return u!==c&&Po(t.style,u,c),false}exportDOM(e){const t=Xl().createElement("pre");vu(t,e._config.theme.code),t.setAttribute("spellcheck","false");const n=this.getLanguage();n&&(t.setAttribute(_e,n),this.getIsSyntaxHighlightSupported()&&t.setAttribute(Se,n));const r=this.getTheme();r&&t.setAttribute(ye,r);const i=this.getStyle();return i&&Po(t.style,i),{element:t}}static importDOM(){return {code:e=>null!=e.textContent&&(/\r?\n/.test(e.textContent)||xe$1(e,"BR"))?{conversion:Te$1,priority:1}:null,div:()=>({conversion:Ae,priority:1}),pre:()=>({conversion:Te$1,priority:0}),table:e=>He(e)?{conversion:Oe,priority:3}:null,td:e=>{const t=e,n=t.closest("table");return t.classList.contains("js-file-line")||n&&He(n)?{conversion:Pe,priority:3}:null},tr:e=>{const t=e.closest("table");return t&&He(t)?{conversion:Pe,priority:3}:null}}}static importJSON(e){return Ce$1().updateFromJSON(e)}updateFromJSON(e){return super.updateFromJSON(e).setLanguage(e.language).setTheme(e.theme)}exportJSON(){return {...super.exportJSON(),language:this.getLanguage(),theme:this.getTheme()}}insertNewAfter(e,t=true){if(!sn$1(Cc(),"@lexical/code")){const t=De(e);if(t)return t}const{anchor:n,focus:r}=e,i=(n.isBefore(r)?n:r).getNode();if(Xo(i)){let e=ue$1(i);const t=[];for(;;)if(er(e))t.push(tr()),e=e.getNextSibling();else {if(!Le(e))break;{let n=0;const r=e.getTextContent(),i=e.getTextContentSize();for(;n<i&&" "===r[n];)n++;if(0!==n&&t.push(Fe(" ".repeat(n))),n!==i)break;e=e.getNextSibling();}}const r=i.splitText(n.offset)[0],o=0===n.offset?0:1,s=r.getIndexWithinParent()+o,l=i.getParentOrThrow(),c=[Vi(),...t];l.splice(s,0,c);const a=t[t.length-1];a?a.select():0===n.offset?r.selectPrevious():r.getNextSibling().selectNext(0,0);}if(Ne(i)){const{offset:t}=e.anchor;i.splice(t,0,[Vi()]),i.select(t+1,t+1);}return null}canIndent(){return  false}collapseAtStart(){const e=ts();return this.getChildren().forEach(t=>e.append(t)),this.replace(e),true}setLanguage(e){const t=this.getWritable();return t.__language=e||void 0,t}getLanguage(){return this.getLatest().__language}setIsSyntaxHighlightSupported(e){const t=this.getWritable();return t.__isSyntaxHighlightSupported=e,t}getIsSyntaxHighlightSupported(){return this.getLatest().__isSyntaxHighlightSupported}setTheme(e){const t=this.getWritable();return t.__theme=e||void 0,t}getTheme(){return this.getLatest().__theme}}function Ce$1(e,t){return Hc(ve).setLanguage(e).setTheme(t)}function Ne(e){return e instanceof ve}function Te$1(e){return {node:Ce$1(e.getAttribute(_e))}}function Ae(e){const t=e,n=we(t);return n||function(e){let t=e.parentElement;for(;null!==t;){if(we(t))return  true;t=t.parentElement;}return  false}(t)?{node:n?Ce$1():null}:{node:null}}function Oe(){return {node:Ce$1()}}function Pe(){return {node:null}}function we(e){return null!==e.style.fontFamily.match("monospace")}function He(e){return e.classList.contains("js-file-line-container")}function De(e){const{anchor:t}=e;if(e.isCollapsed()&&"element"===t.type){const e=t.getNode();if(Ne(e)){const n=e.getChildrenSize();if(n>=2&&t.offset===n){const t=e.getLastChild();if(qi(t)&&qi(t.getPreviousSibling())){const t=ts();return e.splice(n-2,2,[]).insertAfter(t,false),t.select(),t}}}}return null}class ke extends Wo{__highlightType;constructor(e="",t,n){super(e,n),this.__highlightType=t;}static getType(){return "code-highlight"}static clone(e){return new ke(e.__text,e.__highlightType||void 0,e.__key)}afterCloneFrom(e){super.afterCloneFrom(e),this.__highlightType=e.__highlightType;}getHighlightType(){return this.getLatest().__highlightType}setHighlightType(e){const t=this.getWritable();return t.__highlightType=e||void 0,t}canHaveFormat(){return  false}createDOM(e){const t=super.createDOM(e),n=Be(e.theme,this.__highlightType);return vu(t,n),t}updateDOM(e,t,n){const r=super.updateDOM(e,t,n),i=Be(n.theme,e.__highlightType),o=Be(n.theme,this.__highlightType);return i!==o&&(i&&Tu(t,i),o&&vu(t,o)),r}static importJSON(e){return Fe().updateFromJSON(e)}updateFromJSON(e){return super.updateFromJSON(e).setHighlightType(e.highlightType)}exportJSON(){return {...super.exportJSON(),highlightType:this.getHighlightType()}}setFormat(e){return this}isParentRequired(){return  true}createParentElementNode(){return Ce$1()}}function Be(e,t){return t&&e&&e.codeHighlight&&e.codeHighlight[t]}function Fe(e="",t){return Bl(new ke(e,t))}function Le(e){return e instanceof ke}const $e="data-language";function Ee(e){return null!==e.style.fontFamily.match("monospace")}function Me(e){let t=e.parentElement;for(;null!==t;){if(Ee(t))return  true;t=t.parentElement;}return  false}const Je=/* @__PURE__ */fn$1([/* @__PURE__ */Se$1({$import:(e,t)=>e.$importChildren(t),match:Cn$1.tag("tr","td"),name:"@lexical/code/github-code-table/unwrap"})]),ze=/* @__PURE__ */Se$1({$import:(e,t)=>[Ce$1(t.getAttribute($e)).splice(0,0,e.$importChildren(t))],match:Cn$1.tag("pre"),name:"@lexical/code/pre"}),Ke=/* @__PURE__ */Se$1({$import:(e,t,n)=>{const r=t.textContent||"";return /\r?\n/.test(r)||null!==t.querySelector("br")?[Ce$1(t.getAttribute($e)).splice(0,0,e.$importChildren(t))]:n()},match:Cn$1.tag("code"),name:"@lexical/code/code-multiline"});function Ie(e){if(!dc(e))return  false;const t=e.style.fontFamily,n=e.style.whiteSpace;return "string"==typeof t&&/monospace/i.test(t)&&"string"==typeof n&&n.startsWith("pre")}function je(e){let t=false;const n=[];let r="",i=false;const o=()=>{i&&(n.push(r),r="",i=false);};for(const s of Array.from(e.childNodes))if(dc(s))"DIV"===s.tagName?(o(),n.push(s.textContent||""),t=true):"BR"===s.tagName?(o(),n.push(""),t=true):(r+=s.textContent||"",i=true);else if(Ls(s)){const e=s.textContent||"";e.length>0&&(r+=e,i=true);}return o(),t?n:null}/* @__PURE__ */fn$1([/* @__PURE__ */Se$1({$import:(e,t,n)=>{if(!Ie(t)||Me(t))return n();const r=je(t);return null===r||0===r.length?n():[Ce$1().splice(0,0,Vr(r.join("\n")))]},match:Cn$1.tag("div"),name:"@lexical/code/vscode-wrapper"}),/* @__PURE__ */Se$1({$import:(e,t,n)=>{if(!Ie(t)||Me(t))return n();const r=t.previousElementSibling;if(r&&Ie(r))return [];const i=[];let o=t;for(;o&&Ie(o);)i.push("BR"===o.tagName?"":o.textContent||""),o=o.nextElementSibling;return i.length<2?n():[Ce$1().splice(0,0,Vr(i.join("\n")))]},match:Cn$1.tag("div","br"),name:"@lexical/code/vscode-line-run"})]);const qe=/* @__PURE__ */Se$1({$import:(e,t,n)=>Ee(t)?[Ce$1().splice(0,0,e.$importChildren(t))]:Me(t)?e.$importChildren(t):n(),match:Cn$1.tag("div"),name:"@lexical/code/div"});[/* @__PURE__ */Se$1({$import:(e,t)=>[Ce$1().splice(0,0,e.$importChildren(t,{rules:Je}))],match:Cn$1.tag("table").classAll("js-file-line-container"),name:"@lexical/code/github-code-table"}),/* @__PURE__ */Se$1({$import:(e,t)=>e.$importChildren(t),match:Cn$1.tag("td").classAll("js-file-line"),name:"@lexical/code/github-code-cell-by-class"}),Ke,ze,qe];

	var commonjsGlobal = typeof globalThis !== 'undefined' ? globalThis : typeof window !== 'undefined' ? window : typeof global !== 'undefined' ? global : typeof self !== 'undefined' ? self : {};

	var prism = {exports: {}};

	var hasRequiredPrism;

	function requirePrism () {
		if (hasRequiredPrism) return prism.exports;
		hasRequiredPrism = 1;
		(function (module) {
			/* **********************************************
			     Begin prism-core.js
			********************************************** */

			/// <reference lib="WebWorker"/>

			var _self = (typeof window !== 'undefined')
				? window   // if in browser
				: (
					(typeof WorkerGlobalScope !== 'undefined' && self instanceof WorkerGlobalScope)
						? self // if in worker
						: {}   // if in node js
				);

			/**
			 * Prism: Lightweight, robust, elegant syntax highlighting
			 *
			 * @license MIT <https://opensource.org/licenses/MIT>
			 * @author Lea Verou <https://lea.verou.me>
			 * @namespace
			 * @public
			 */
			var Prism = (function (_self) {

				// Private helper vars
				var lang = /(?:^|\s)lang(?:uage)?-([\w-]+)(?=\s|$)/i;
				var uniqueId = 0;

				// The grammar object for plaintext
				var plainTextGrammar = {};


				var _ = {
					/**
					 * By default, Prism will attempt to highlight all code elements (by calling {@link Prism.highlightAll}) on the
					 * current page after the page finished loading. This might be a problem if e.g. you wanted to asynchronously load
					 * additional languages or plugins yourself.
					 *
					 * By setting this value to `true`, Prism will not automatically highlight all code elements on the page.
					 *
					 * You obviously have to change this value before the automatic highlighting started. To do this, you can add an
					 * empty Prism object into the global scope before loading the Prism script like this:
					 *
					 * ```js
					 * window.Prism = window.Prism || {};
					 * Prism.manual = true;
					 * // add a new <script> to load Prism's script
					 * ```
					 *
					 * @default false
					 * @type {boolean}
					 * @memberof Prism
					 * @public
					 */
					manual: _self.Prism && _self.Prism.manual,
					/**
					 * By default, if Prism is in a web worker, it assumes that it is in a worker it created itself, so it uses
					 * `addEventListener` to communicate with its parent instance. However, if you're using Prism manually in your
					 * own worker, you don't want it to do this.
					 *
					 * By setting this value to `true`, Prism will not add its own listeners to the worker.
					 *
					 * You obviously have to change this value before Prism executes. To do this, you can add an
					 * empty Prism object into the global scope before loading the Prism script like this:
					 *
					 * ```js
					 * window.Prism = window.Prism || {};
					 * Prism.disableWorkerMessageHandler = true;
					 * // Load Prism's script
					 * ```
					 *
					 * @default false
					 * @type {boolean}
					 * @memberof Prism
					 * @public
					 */
					disableWorkerMessageHandler: _self.Prism && _self.Prism.disableWorkerMessageHandler,

					/**
					 * A namespace for utility methods.
					 *
					 * All function in this namespace that are not explicitly marked as _public_ are for __internal use only__ and may
					 * change or disappear at any time.
					 *
					 * @namespace
					 * @memberof Prism
					 */
					util: {
						encode: function encode(tokens) {
							if (tokens instanceof Token) {
								return new Token(tokens.type, encode(tokens.content), tokens.alias);
							} else if (Array.isArray(tokens)) {
								return tokens.map(encode);
							} else {
								return tokens.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/\u00a0/g, ' ');
							}
						},

						/**
						 * Returns the name of the type of the given value.
						 *
						 * @param {any} o
						 * @returns {string}
						 * @example
						 * type(null)      === 'Null'
						 * type(undefined) === 'Undefined'
						 * type(123)       === 'Number'
						 * type('foo')     === 'String'
						 * type(true)      === 'Boolean'
						 * type([1, 2])    === 'Array'
						 * type({})        === 'Object'
						 * type(String)    === 'Function'
						 * type(/abc+/)    === 'RegExp'
						 */
						type: function (o) {
							return Object.prototype.toString.call(o).slice(8, -1);
						},

						/**
						 * Returns a unique number for the given object. Later calls will still return the same number.
						 *
						 * @param {Object} obj
						 * @returns {number}
						 */
						objId: function (obj) {
							if (!obj['__id']) {
								Object.defineProperty(obj, '__id', { value: ++uniqueId });
							}
							return obj['__id'];
						},

						/**
						 * Creates a deep clone of the given object.
						 *
						 * The main intended use of this function is to clone language definitions.
						 *
						 * @param {T} o
						 * @param {Record<number, any>} [visited]
						 * @returns {T}
						 * @template T
						 */
						clone: function deepClone(o, visited) {
							visited = visited || {};

							var clone; var id;
							switch (_.util.type(o)) {
								case 'Object':
									id = _.util.objId(o);
									if (visited[id]) {
										return visited[id];
									}
									clone = /** @type {Record<string, any>} */ ({});
									visited[id] = clone;

									for (var key in o) {
										if (o.hasOwnProperty(key)) {
											clone[key] = deepClone(o[key], visited);
										}
									}

									return /** @type {any} */ (clone);

								case 'Array':
									id = _.util.objId(o);
									if (visited[id]) {
										return visited[id];
									}
									clone = [];
									visited[id] = clone;

									(/** @type {Array} */(/** @type {any} */(o))).forEach(function (v, i) {
										clone[i] = deepClone(v, visited);
									});

									return /** @type {any} */ (clone);

								default:
									return o;
							}
						},

						/**
						 * Returns the Prism language of the given element set by a `language-xxxx` or `lang-xxxx` class.
						 *
						 * If no language is set for the element or the element is `null` or `undefined`, `none` will be returned.
						 *
						 * @param {Element} element
						 * @returns {string}
						 */
						getLanguage: function (element) {
							while (element) {
								var m = lang.exec(element.className);
								if (m) {
									return m[1].toLowerCase();
								}
								element = element.parentElement;
							}
							return 'none';
						},

						/**
						 * Sets the Prism `language-xxxx` class of the given element.
						 *
						 * @param {Element} element
						 * @param {string} language
						 * @returns {void}
						 */
						setLanguage: function (element, language) {
							// remove all `language-xxxx` classes
							// (this might leave behind a leading space)
							element.className = element.className.replace(RegExp(lang, 'gi'), '');

							// add the new `language-xxxx` class
							// (using `classList` will automatically clean up spaces for us)
							element.classList.add('language-' + language);
						},

						/**
						 * Returns the script element that is currently executing.
						 *
						 * This does __not__ work for line script element.
						 *
						 * @returns {HTMLScriptElement | null}
						 */
						currentScript: function () {
							if (typeof document === 'undefined') {
								return null;
							}
							if (document.currentScript && document.currentScript.tagName === 'SCRIPT' && 1 < 2 /* hack to trip TS' flow analysis */) {
								return /** @type {any} */ (document.currentScript);
							}

							// IE11 workaround
							// we'll get the src of the current script by parsing IE11's error stack trace
							// this will not work for inline scripts

							try {
								throw new Error();
							} catch (err) {
								// Get file src url from stack. Specifically works with the format of stack traces in IE.
								// A stack will look like this:
								//
								// Error
								//    at _.util.currentScript (http://localhost/components/prism-core.js:119:5)
								//    at Global code (http://localhost/components/prism-core.js:606:1)

								var src = (/at [^(\r\n]*\((.*):[^:]+:[^:]+\)$/i.exec(err.stack) || [])[1];
								if (src) {
									var scripts = document.getElementsByTagName('script');
									for (var i in scripts) {
										if (scripts[i].src == src) {
											return scripts[i];
										}
									}
								}
								return null;
							}
						},

						/**
						 * Returns whether a given class is active for `element`.
						 *
						 * The class can be activated if `element` or one of its ancestors has the given class and it can be deactivated
						 * if `element` or one of its ancestors has the negated version of the given class. The _negated version_ of the
						 * given class is just the given class with a `no-` prefix.
						 *
						 * Whether the class is active is determined by the closest ancestor of `element` (where `element` itself is
						 * closest ancestor) that has the given class or the negated version of it. If neither `element` nor any of its
						 * ancestors have the given class or the negated version of it, then the default activation will be returned.
						 *
						 * In the paradoxical situation where the closest ancestor contains __both__ the given class and the negated
						 * version of it, the class is considered active.
						 *
						 * @param {Element} element
						 * @param {string} className
						 * @param {boolean} [defaultActivation=false]
						 * @returns {boolean}
						 */
						isActive: function (element, className, defaultActivation) {
							var no = 'no-' + className;

							while (element) {
								var classList = element.classList;
								if (classList.contains(className)) {
									return true;
								}
								if (classList.contains(no)) {
									return false;
								}
								element = element.parentElement;
							}
							return !!defaultActivation;
						}
					},

					/**
					 * This namespace contains all currently loaded languages and the some helper functions to create and modify languages.
					 *
					 * @namespace
					 * @memberof Prism
					 * @public
					 */
					languages: {
						/**
						 * The grammar for plain, unformatted text.
						 */
						plain: plainTextGrammar,
						plaintext: plainTextGrammar,
						text: plainTextGrammar,
						txt: plainTextGrammar,

						/**
						 * Creates a deep copy of the language with the given id and appends the given tokens.
						 *
						 * If a token in `redef` also appears in the copied language, then the existing token in the copied language
						 * will be overwritten at its original position.
						 *
						 * ## Best practices
						 *
						 * Since the position of overwriting tokens (token in `redef` that overwrite tokens in the copied language)
						 * doesn't matter, they can technically be in any order. However, this can be confusing to others that trying to
						 * understand the language definition because, normally, the order of tokens matters in Prism grammars.
						 *
						 * Therefore, it is encouraged to order overwriting tokens according to the positions of the overwritten tokens.
						 * Furthermore, all non-overwriting tokens should be placed after the overwriting ones.
						 *
						 * @param {string} id The id of the language to extend. This has to be a key in `Prism.languages`.
						 * @param {Grammar} redef The new tokens to append.
						 * @returns {Grammar} The new language created.
						 * @public
						 * @example
						 * Prism.languages['css-with-colors'] = Prism.languages.extend('css', {
						 *     // Prism.languages.css already has a 'comment' token, so this token will overwrite CSS' 'comment' token
						 *     // at its original position
						 *     'comment': { ... },
						 *     // CSS doesn't have a 'color' token, so this token will be appended
						 *     'color': /\b(?:red|green|blue)\b/
						 * });
						 */
						extend: function (id, redef) {
							var lang = _.util.clone(_.languages[id]);

							for (var key in redef) {
								lang[key] = redef[key];
							}

							return lang;
						},

						/**
						 * Inserts tokens _before_ another token in a language definition or any other grammar.
						 *
						 * ## Usage
						 *
						 * This helper method makes it easy to modify existing languages. For example, the CSS language definition
						 * not only defines CSS highlighting for CSS documents, but also needs to define highlighting for CSS embedded
						 * in HTML through `<style>` elements. To do this, it needs to modify `Prism.languages.markup` and add the
						 * appropriate tokens. However, `Prism.languages.markup` is a regular JavaScript object literal, so if you do
						 * this:
						 *
						 * ```js
						 * Prism.languages.markup.style = {
						 *     // token
						 * };
						 * ```
						 *
						 * then the `style` token will be added (and processed) at the end. `insertBefore` allows you to insert tokens
						 * before existing tokens. For the CSS example above, you would use it like this:
						 *
						 * ```js
						 * Prism.languages.insertBefore('markup', 'cdata', {
						 *     'style': {
						 *         // token
						 *     }
						 * });
						 * ```
						 *
						 * ## Special cases
						 *
						 * If the grammars of `inside` and `insert` have tokens with the same name, the tokens in `inside`'s grammar
						 * will be ignored.
						 *
						 * This behavior can be used to insert tokens after `before`:
						 *
						 * ```js
						 * Prism.languages.insertBefore('markup', 'comment', {
						 *     'comment': Prism.languages.markup.comment,
						 *     // tokens after 'comment'
						 * });
						 * ```
						 *
						 * ## Limitations
						 *
						 * The main problem `insertBefore` has to solve is iteration order. Since ES2015, the iteration order for object
						 * properties is guaranteed to be the insertion order (except for integer keys) but some browsers behave
						 * differently when keys are deleted and re-inserted. So `insertBefore` can't be implemented by temporarily
						 * deleting properties which is necessary to insert at arbitrary positions.
						 *
						 * To solve this problem, `insertBefore` doesn't actually insert the given tokens into the target object.
						 * Instead, it will create a new object and replace all references to the target object with the new one. This
						 * can be done without temporarily deleting properties, so the iteration order is well-defined.
						 *
						 * However, only references that can be reached from `Prism.languages` or `insert` will be replaced. I.e. if
						 * you hold the target object in a variable, then the value of the variable will not change.
						 *
						 * ```js
						 * var oldMarkup = Prism.languages.markup;
						 * var newMarkup = Prism.languages.insertBefore('markup', 'comment', { ... });
						 *
						 * assert(oldMarkup !== Prism.languages.markup);
						 * assert(newMarkup === Prism.languages.markup);
						 * ```
						 *
						 * @param {string} inside The property of `root` (e.g. a language id in `Prism.languages`) that contains the
						 * object to be modified.
						 * @param {string} before The key to insert before.
						 * @param {Grammar} insert An object containing the key-value pairs to be inserted.
						 * @param {Object<string, any>} [root] The object containing `inside`, i.e. the object that contains the
						 * object to be modified.
						 *
						 * Defaults to `Prism.languages`.
						 * @returns {Grammar} The new grammar object.
						 * @public
						 */
						insertBefore: function (inside, before, insert, root) {
							root = root || /** @type {any} */ (_.languages);
							var grammar = root[inside];
							/** @type {Grammar} */
							var ret = {};

							for (var token in grammar) {
								if (grammar.hasOwnProperty(token)) {

									if (token == before) {
										for (var newToken in insert) {
											if (insert.hasOwnProperty(newToken)) {
												ret[newToken] = insert[newToken];
											}
										}
									}

									// Do not insert token which also occur in insert. See #1525
									if (!insert.hasOwnProperty(token)) {
										ret[token] = grammar[token];
									}
								}
							}

							var old = root[inside];
							root[inside] = ret;

							// Update references in other language definitions
							_.languages.DFS(_.languages, function (key, value) {
								if (value === old && key != inside) {
									this[key] = ret;
								}
							});

							return ret;
						},

						// Traverse a language definition with Depth First Search
						DFS: function DFS(o, callback, type, visited) {
							visited = visited || {};

							var objId = _.util.objId;

							for (var i in o) {
								if (o.hasOwnProperty(i)) {
									callback.call(o, i, o[i], type || i);

									var property = o[i];
									var propertyType = _.util.type(property);

									if (propertyType === 'Object' && !visited[objId(property)]) {
										visited[objId(property)] = true;
										DFS(property, callback, null, visited);
									} else if (propertyType === 'Array' && !visited[objId(property)]) {
										visited[objId(property)] = true;
										DFS(property, callback, i, visited);
									}
								}
							}
						}
					},

					plugins: {},

					/**
					 * This is the most high-level function in Prism’s API.
					 * It fetches all the elements that have a `.language-xxxx` class and then calls {@link Prism.highlightElement} on
					 * each one of them.
					 *
					 * This is equivalent to `Prism.highlightAllUnder(document, async, callback)`.
					 *
					 * @param {boolean} [async=false] Same as in {@link Prism.highlightAllUnder}.
					 * @param {HighlightCallback} [callback] Same as in {@link Prism.highlightAllUnder}.
					 * @memberof Prism
					 * @public
					 */
					highlightAll: function (async, callback) {
						_.highlightAllUnder(document, async, callback);
					},

					/**
					 * Fetches all the descendants of `container` that have a `.language-xxxx` class and then calls
					 * {@link Prism.highlightElement} on each one of them.
					 *
					 * The following hooks will be run:
					 * 1. `before-highlightall`
					 * 2. `before-all-elements-highlight`
					 * 3. All hooks of {@link Prism.highlightElement} for each element.
					 *
					 * @param {ParentNode} container The root element, whose descendants that have a `.language-xxxx` class will be highlighted.
					 * @param {boolean} [async=false] Whether each element is to be highlighted asynchronously using Web Workers.
					 * @param {HighlightCallback} [callback] An optional callback to be invoked on each element after its highlighting is done.
					 * @memberof Prism
					 * @public
					 */
					highlightAllUnder: function (container, async, callback) {
						var env = {
							callback: callback,
							container: container,
							selector: 'code[class*="language-"], [class*="language-"] code, code[class*="lang-"], [class*="lang-"] code'
						};

						_.hooks.run('before-highlightall', env);

						env.elements = Array.prototype.slice.apply(env.container.querySelectorAll(env.selector));

						_.hooks.run('before-all-elements-highlight', env);

						for (var i = 0, element; (element = env.elements[i++]);) {
							_.highlightElement(element, async === true, env.callback);
						}
					},

					/**
					 * Highlights the code inside a single element.
					 *
					 * The following hooks will be run:
					 * 1. `before-sanity-check`
					 * 2. `before-highlight`
					 * 3. All hooks of {@link Prism.highlight}. These hooks will be run by an asynchronous worker if `async` is `true`.
					 * 4. `before-insert`
					 * 5. `after-highlight`
					 * 6. `complete`
					 *
					 * Some the above hooks will be skipped if the element doesn't contain any text or there is no grammar loaded for
					 * the element's language.
					 *
					 * @param {Element} element The element containing the code.
					 * It must have a class of `language-xxxx` to be processed, where `xxxx` is a valid language identifier.
					 * @param {boolean} [async=false] Whether the element is to be highlighted asynchronously using Web Workers
					 * to improve performance and avoid blocking the UI when highlighting very large chunks of code. This option is
					 * [disabled by default](https://prismjs.com/faq.html#why-is-asynchronous-highlighting-disabled-by-default).
					 *
					 * Note: All language definitions required to highlight the code must be included in the main `prism.js` file for
					 * asynchronous highlighting to work. You can build your own bundle on the
					 * [Download page](https://prismjs.com/download.html).
					 * @param {HighlightCallback} [callback] An optional callback to be invoked after the highlighting is done.
					 * Mostly useful when `async` is `true`, since in that case, the highlighting is done asynchronously.
					 * @memberof Prism
					 * @public
					 */
					highlightElement: function (element, async, callback) {
						// Find language
						var language = _.util.getLanguage(element);
						var grammar = _.languages[language];

						// Set language on the element, if not present
						_.util.setLanguage(element, language);

						// Set language on the parent, for styling
						var parent = element.parentElement;
						if (parent && parent.nodeName.toLowerCase() === 'pre') {
							_.util.setLanguage(parent, language);
						}

						var code = element.textContent;

						var env = {
							element: element,
							language: language,
							grammar: grammar,
							code: code
						};

						function insertHighlightedCode(highlightedCode) {
							env.highlightedCode = highlightedCode;

							_.hooks.run('before-insert', env);

							env.element.innerHTML = env.highlightedCode;

							_.hooks.run('after-highlight', env);
							_.hooks.run('complete', env);
							callback && callback.call(env.element);
						}

						_.hooks.run('before-sanity-check', env);

						// plugins may change/add the parent/element
						parent = env.element.parentElement;
						if (parent && parent.nodeName.toLowerCase() === 'pre' && !parent.hasAttribute('tabindex')) {
							parent.setAttribute('tabindex', '0');
						}

						if (!env.code) {
							_.hooks.run('complete', env);
							callback && callback.call(env.element);
							return;
						}

						_.hooks.run('before-highlight', env);

						if (!env.grammar) {
							insertHighlightedCode(_.util.encode(env.code));
							return;
						}

						if (async && _self.Worker) {
							var worker = new Worker(_.filename);

							worker.onmessage = function (evt) {
								insertHighlightedCode(evt.data);
							};

							worker.postMessage(JSON.stringify({
								language: env.language,
								code: env.code,
								immediateClose: true
							}));
						} else {
							insertHighlightedCode(_.highlight(env.code, env.grammar, env.language));
						}
					},

					/**
					 * Low-level function, only use if you know what you’re doing. It accepts a string of text as input
					 * and the language definitions to use, and returns a string with the HTML produced.
					 *
					 * The following hooks will be run:
					 * 1. `before-tokenize`
					 * 2. `after-tokenize`
					 * 3. `wrap`: On each {@link Token}.
					 *
					 * @param {string} text A string with the code to be highlighted.
					 * @param {Grammar} grammar An object containing the tokens to use.
					 *
					 * Usually a language definition like `Prism.languages.markup`.
					 * @param {string} language The name of the language definition passed to `grammar`.
					 * @returns {string} The highlighted HTML.
					 * @memberof Prism
					 * @public
					 * @example
					 * Prism.highlight('var foo = true;', Prism.languages.javascript, 'javascript');
					 */
					highlight: function (text, grammar, language) {
						var env = {
							code: text,
							grammar: grammar,
							language: language
						};
						_.hooks.run('before-tokenize', env);
						if (!env.grammar) {
							throw new Error('The language "' + env.language + '" has no grammar.');
						}
						env.tokens = _.tokenize(env.code, env.grammar);
						_.hooks.run('after-tokenize', env);
						return Token.stringify(_.util.encode(env.tokens), env.language);
					},

					/**
					 * This is the heart of Prism, and the most low-level function you can use. It accepts a string of text as input
					 * and the language definitions to use, and returns an array with the tokenized code.
					 *
					 * When the language definition includes nested tokens, the function is called recursively on each of these tokens.
					 *
					 * This method could be useful in other contexts as well, as a very crude parser.
					 *
					 * @param {string} text A string with the code to be highlighted.
					 * @param {Grammar} grammar An object containing the tokens to use.
					 *
					 * Usually a language definition like `Prism.languages.markup`.
					 * @returns {TokenStream} An array of strings and tokens, a token stream.
					 * @memberof Prism
					 * @public
					 * @example
					 * let code = `var foo = 0;`;
					 * let tokens = Prism.tokenize(code, Prism.languages.javascript);
					 * tokens.forEach(token => {
					 *     if (token instanceof Prism.Token && token.type === 'number') {
					 *         console.log(`Found numeric literal: ${token.content}`);
					 *     }
					 * });
					 */
					tokenize: function (text, grammar) {
						var rest = grammar.rest;
						if (rest) {
							for (var token in rest) {
								grammar[token] = rest[token];
							}

							delete grammar.rest;
						}

						var tokenList = new LinkedList();
						addAfter(tokenList, tokenList.head, text);

						matchGrammar(text, tokenList, grammar, tokenList.head, 0);

						return toArray(tokenList);
					},

					/**
					 * @namespace
					 * @memberof Prism
					 * @public
					 */
					hooks: {
						all: {},

						/**
						 * Adds the given callback to the list of callbacks for the given hook.
						 *
						 * The callback will be invoked when the hook it is registered for is run.
						 * Hooks are usually directly run by a highlight function but you can also run hooks yourself.
						 *
						 * One callback function can be registered to multiple hooks and the same hook multiple times.
						 *
						 * @param {string} name The name of the hook.
						 * @param {HookCallback} callback The callback function which is given environment variables.
						 * @public
						 */
						add: function (name, callback) {
							var hooks = _.hooks.all;

							hooks[name] = hooks[name] || [];

							hooks[name].push(callback);
						},

						/**
						 * Runs a hook invoking all registered callbacks with the given environment variables.
						 *
						 * Callbacks will be invoked synchronously and in the order in which they were registered.
						 *
						 * @param {string} name The name of the hook.
						 * @param {Object<string, any>} env The environment variables of the hook passed to all callbacks registered.
						 * @public
						 */
						run: function (name, env) {
							var callbacks = _.hooks.all[name];

							if (!callbacks || !callbacks.length) {
								return;
							}

							for (var i = 0, callback; (callback = callbacks[i++]);) {
								callback(env);
							}
						}
					},

					Token: Token
				};
				_self.Prism = _;


				// Typescript note:
				// The following can be used to import the Token type in JSDoc:
				//
				//   @typedef {InstanceType<import("./prism-core")["Token"]>} Token

				/**
				 * Creates a new token.
				 *
				 * @param {string} type See {@link Token#type type}
				 * @param {string | TokenStream} content See {@link Token#content content}
				 * @param {string|string[]} [alias] The alias(es) of the token.
				 * @param {string} [matchedStr=""] A copy of the full string this token was created from.
				 * @class
				 * @global
				 * @public
				 */
				function Token(type, content, alias, matchedStr) {
					/**
					 * The type of the token.
					 *
					 * This is usually the key of a pattern in a {@link Grammar}.
					 *
					 * @type {string}
					 * @see GrammarToken
					 * @public
					 */
					this.type = type;
					/**
					 * The strings or tokens contained by this token.
					 *
					 * This will be a token stream if the pattern matched also defined an `inside` grammar.
					 *
					 * @type {string | TokenStream}
					 * @public
					 */
					this.content = content;
					/**
					 * The alias(es) of the token.
					 *
					 * @type {string|string[]}
					 * @see GrammarToken
					 * @public
					 */
					this.alias = alias;
					// Copy of the full string this token was created from
					this.length = (matchedStr || '').length | 0;
				}

				/**
				 * A token stream is an array of strings and {@link Token Token} objects.
				 *
				 * Token streams have to fulfill a few properties that are assumed by most functions (mostly internal ones) that process
				 * them.
				 *
				 * 1. No adjacent strings.
				 * 2. No empty strings.
				 *
				 *    The only exception here is the token stream that only contains the empty string and nothing else.
				 *
				 * @typedef {Array<string | Token>} TokenStream
				 * @global
				 * @public
				 */

				/**
				 * Converts the given token or token stream to an HTML representation.
				 *
				 * The following hooks will be run:
				 * 1. `wrap`: On each {@link Token}.
				 *
				 * @param {string | Token | TokenStream} o The token or token stream to be converted.
				 * @param {string} language The name of current language.
				 * @returns {string} The HTML representation of the token or token stream.
				 * @memberof Token
				 * @static
				 */
				Token.stringify = function stringify(o, language) {
					if (typeof o == 'string') {
						return o;
					}
					if (Array.isArray(o)) {
						var s = '';
						o.forEach(function (e) {
							s += stringify(e, language);
						});
						return s;
					}

					var env = {
						type: o.type,
						content: stringify(o.content, language),
						tag: 'span',
						classes: ['token', o.type],
						attributes: {},
						language: language
					};

					var aliases = o.alias;
					if (aliases) {
						if (Array.isArray(aliases)) {
							Array.prototype.push.apply(env.classes, aliases);
						} else {
							env.classes.push(aliases);
						}
					}

					_.hooks.run('wrap', env);

					var attributes = '';
					for (var name in env.attributes) {
						attributes += ' ' + name + '="' + (env.attributes[name] || '').replace(/"/g, '&quot;') + '"';
					}

					return '<' + env.tag + ' class="' + env.classes.join(' ') + '"' + attributes + '>' + env.content + '</' + env.tag + '>';
				};

				/**
				 * @param {RegExp} pattern
				 * @param {number} pos
				 * @param {string} text
				 * @param {boolean} lookbehind
				 * @returns {RegExpExecArray | null}
				 */
				function matchPattern(pattern, pos, text, lookbehind) {
					pattern.lastIndex = pos;
					var match = pattern.exec(text);
					if (match && lookbehind && match[1]) {
						// change the match to remove the text matched by the Prism lookbehind group
						var lookbehindLength = match[1].length;
						match.index += lookbehindLength;
						match[0] = match[0].slice(lookbehindLength);
					}
					return match;
				}

				/**
				 * @param {string} text
				 * @param {LinkedList<string | Token>} tokenList
				 * @param {any} grammar
				 * @param {LinkedListNode<string | Token>} startNode
				 * @param {number} startPos
				 * @param {RematchOptions} [rematch]
				 * @returns {void}
				 * @private
				 *
				 * @typedef RematchOptions
				 * @property {string} cause
				 * @property {number} reach
				 */
				function matchGrammar(text, tokenList, grammar, startNode, startPos, rematch) {
					for (var token in grammar) {
						if (!grammar.hasOwnProperty(token) || !grammar[token]) {
							continue;
						}

						var patterns = grammar[token];
						patterns = Array.isArray(patterns) ? patterns : [patterns];

						for (var j = 0; j < patterns.length; ++j) {
							if (rematch && rematch.cause == token + ',' + j) {
								return;
							}

							var patternObj = patterns[j];
							var inside = patternObj.inside;
							var lookbehind = !!patternObj.lookbehind;
							var greedy = !!patternObj.greedy;
							var alias = patternObj.alias;

							if (greedy && !patternObj.pattern.global) {
								// Without the global flag, lastIndex won't work
								var flags = patternObj.pattern.toString().match(/[imsuy]*$/)[0];
								patternObj.pattern = RegExp(patternObj.pattern.source, flags + 'g');
							}

							/** @type {RegExp} */
							var pattern = patternObj.pattern || patternObj;

							for ( // iterate the token list and keep track of the current token/string position
								var currentNode = startNode.next, pos = startPos;
								currentNode !== tokenList.tail;
								pos += currentNode.value.length, currentNode = currentNode.next
							) {

								if (rematch && pos >= rematch.reach) {
									break;
								}

								var str = currentNode.value;

								if (tokenList.length > text.length) {
									// Something went terribly wrong, ABORT, ABORT!
									return;
								}

								if (str instanceof Token) {
									continue;
								}

								var removeCount = 1; // this is the to parameter of removeBetween
								var match;

								if (greedy) {
									match = matchPattern(pattern, pos, text, lookbehind);
									if (!match || match.index >= text.length) {
										break;
									}

									var from = match.index;
									var to = match.index + match[0].length;
									var p = pos;

									// find the node that contains the match
									p += currentNode.value.length;
									while (from >= p) {
										currentNode = currentNode.next;
										p += currentNode.value.length;
									}
									// adjust pos (and p)
									p -= currentNode.value.length;
									pos = p;

									// the current node is a Token, then the match starts inside another Token, which is invalid
									if (currentNode.value instanceof Token) {
										continue;
									}

									// find the last node which is affected by this match
									for (
										var k = currentNode;
										k !== tokenList.tail && (p < to || typeof k.value === 'string');
										k = k.next
									) {
										removeCount++;
										p += k.value.length;
									}
									removeCount--;

									// replace with the new match
									str = text.slice(pos, p);
									match.index -= pos;
								} else {
									match = matchPattern(pattern, 0, str, lookbehind);
									if (!match) {
										continue;
									}
								}

								// eslint-disable-next-line no-redeclare
								var from = match.index;
								var matchStr = match[0];
								var before = str.slice(0, from);
								var after = str.slice(from + matchStr.length);

								var reach = pos + str.length;
								if (rematch && reach > rematch.reach) {
									rematch.reach = reach;
								}

								var removeFrom = currentNode.prev;

								if (before) {
									removeFrom = addAfter(tokenList, removeFrom, before);
									pos += before.length;
								}

								removeRange(tokenList, removeFrom, removeCount);

								var wrapped = new Token(token, inside ? _.tokenize(matchStr, inside) : matchStr, alias, matchStr);
								currentNode = addAfter(tokenList, removeFrom, wrapped);

								if (after) {
									addAfter(tokenList, currentNode, after);
								}

								if (removeCount > 1) {
									// at least one Token object was removed, so we have to do some rematching
									// this can only happen if the current pattern is greedy

									/** @type {RematchOptions} */
									var nestedRematch = {
										cause: token + ',' + j,
										reach: reach
									};
									matchGrammar(text, tokenList, grammar, currentNode.prev, pos, nestedRematch);

									// the reach might have been extended because of the rematching
									if (rematch && nestedRematch.reach > rematch.reach) {
										rematch.reach = nestedRematch.reach;
									}
								}
							}
						}
					}
				}

				/**
				 * @typedef LinkedListNode
				 * @property {T} value
				 * @property {LinkedListNode<T> | null} prev The previous node.
				 * @property {LinkedListNode<T> | null} next The next node.
				 * @template T
				 * @private
				 */

				/**
				 * @template T
				 * @private
				 */
				function LinkedList() {
					/** @type {LinkedListNode<T>} */
					var head = { value: null, prev: null, next: null };
					/** @type {LinkedListNode<T>} */
					var tail = { value: null, prev: head, next: null };
					head.next = tail;

					/** @type {LinkedListNode<T>} */
					this.head = head;
					/** @type {LinkedListNode<T>} */
					this.tail = tail;
					this.length = 0;
				}

				/**
				 * Adds a new node with the given value to the list.
				 *
				 * @param {LinkedList<T>} list
				 * @param {LinkedListNode<T>} node
				 * @param {T} value
				 * @returns {LinkedListNode<T>} The added node.
				 * @template T
				 */
				function addAfter(list, node, value) {
					// assumes that node != list.tail && values.length >= 0
					var next = node.next;

					var newNode = { value: value, prev: node, next: next };
					node.next = newNode;
					next.prev = newNode;
					list.length++;

					return newNode;
				}
				/**
				 * Removes `count` nodes after the given node. The given node will not be removed.
				 *
				 * @param {LinkedList<T>} list
				 * @param {LinkedListNode<T>} node
				 * @param {number} count
				 * @template T
				 */
				function removeRange(list, node, count) {
					var next = node.next;
					for (var i = 0; i < count && next !== list.tail; i++) {
						next = next.next;
					}
					node.next = next;
					next.prev = node;
					list.length -= i;
				}
				/**
				 * @param {LinkedList<T>} list
				 * @returns {T[]}
				 * @template T
				 */
				function toArray(list) {
					var array = [];
					var node = list.head.next;
					while (node !== list.tail) {
						array.push(node.value);
						node = node.next;
					}
					return array;
				}


				if (!_self.document) {
					if (!_self.addEventListener) {
						// in Node.js
						return _;
					}

					if (!_.disableWorkerMessageHandler) {
						// In worker
						_self.addEventListener('message', function (evt) {
							var message = JSON.parse(evt.data);
							var lang = message.language;
							var code = message.code;
							var immediateClose = message.immediateClose;

							_self.postMessage(_.highlight(code, _.languages[lang], lang));
							if (immediateClose) {
								_self.close();
							}
						}, false);
					}

					return _;
				}

				// Get current script and highlight
				var script = _.util.currentScript();

				if (script) {
					_.filename = script.src;

					if (script.hasAttribute('data-manual')) {
						_.manual = true;
					}
				}

				function highlightAutomaticallyCallback() {
					if (!_.manual) {
						_.highlightAll();
					}
				}

				if (!_.manual) {
					// If the document state is "loading", then we'll use DOMContentLoaded.
					// If the document state is "interactive" and the prism.js script is deferred, then we'll also use the
					// DOMContentLoaded event because there might be some plugins or languages which have also been deferred and they
					// might take longer one animation frame to execute which can create a race condition where only some plugins have
					// been loaded when Prism.highlightAll() is executed, depending on how fast resources are loaded.
					// See https://github.com/PrismJS/prism/issues/2102
					var readyState = document.readyState;
					if (readyState === 'loading' || readyState === 'interactive' && script && script.defer) {
						document.addEventListener('DOMContentLoaded', highlightAutomaticallyCallback);
					} else {
						if (window.requestAnimationFrame) {
							window.requestAnimationFrame(highlightAutomaticallyCallback);
						} else {
							window.setTimeout(highlightAutomaticallyCallback, 16);
						}
					}
				}

				return _;

			}(_self));

			if (module.exports) {
				module.exports = Prism;
			}

			// hack for components to work correctly in node.js
			if (typeof commonjsGlobal !== 'undefined') {
				commonjsGlobal.Prism = Prism;
			}

			// some additional documentation/types

			/**
			 * The expansion of a simple `RegExp` literal to support additional properties.
			 *
			 * @typedef GrammarToken
			 * @property {RegExp} pattern The regular expression of the token.
			 * @property {boolean} [lookbehind=false] If `true`, then the first capturing group of `pattern` will (effectively)
			 * behave as a lookbehind group meaning that the captured text will not be part of the matched text of the new token.
			 * @property {boolean} [greedy=false] Whether the token is greedy.
			 * @property {string|string[]} [alias] An optional alias or list of aliases.
			 * @property {Grammar} [inside] The nested grammar of this token.
			 *
			 * The `inside` grammar will be used to tokenize the text value of each token of this kind.
			 *
			 * This can be used to make nested and even recursive language definitions.
			 *
			 * Note: This can cause infinite recursion. Be careful when you embed different languages or even the same language into
			 * each another.
			 * @global
			 * @public
			 */

			/**
			 * @typedef Grammar
			 * @type {Object<string, RegExp | GrammarToken | Array<RegExp | GrammarToken>>}
			 * @property {Grammar} [rest] An optional grammar object that will be appended to this grammar.
			 * @global
			 * @public
			 */

			/**
			 * A function which will invoked after an element was successfully highlighted.
			 *
			 * @callback HighlightCallback
			 * @param {Element} element The element successfully highlighted.
			 * @returns {void}
			 * @global
			 * @public
			 */

			/**
			 * @callback HookCallback
			 * @param {Object<string, any>} env The environment variables of the hook.
			 * @returns {void}
			 * @global
			 * @public
			 */


			/* **********************************************
			     Begin prism-markup.js
			********************************************** */

			Prism.languages.markup = {
				'comment': {
					pattern: /<!--(?:(?!<!--)[\s\S])*?-->/,
					greedy: true
				},
				'prolog': {
					pattern: /<\?[\s\S]+?\?>/,
					greedy: true
				},
				'doctype': {
					// https://www.w3.org/TR/xml/#NT-doctypedecl
					pattern: /<!DOCTYPE(?:[^>"'[\]]|"[^"]*"|'[^']*')+(?:\[(?:[^<"'\]]|"[^"]*"|'[^']*'|<(?!!--)|<!--(?:[^-]|-(?!->))*-->)*\]\s*)?>/i,
					greedy: true,
					inside: {
						'internal-subset': {
							pattern: /(^[^\[]*\[)[\s\S]+(?=\]>$)/,
							lookbehind: true,
							greedy: true,
							inside: null // see below
						},
						'string': {
							pattern: /"[^"]*"|'[^']*'/,
							greedy: true
						},
						'punctuation': /^<!|>$|[[\]]/,
						'doctype-tag': /^DOCTYPE/i,
						'name': /[^\s<>'"]+/
					}
				},
				'cdata': {
					pattern: /<!\[CDATA\[[\s\S]*?\]\]>/i,
					greedy: true
				},
				'tag': {
					pattern: /<\/?(?!\d)[^\s>\/=$<%]+(?:\s(?:\s*[^\s>\/=]+(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s'">=]+(?=[\s>]))|(?=[\s/>])))+)?\s*\/?>/,
					greedy: true,
					inside: {
						'tag': {
							pattern: /^<\/?[^\s>\/]+/,
							inside: {
								'punctuation': /^<\/?/,
								'namespace': /^[^\s>\/:]+:/
							}
						},
						'special-attr': [],
						'attr-value': {
							pattern: /=\s*(?:"[^"]*"|'[^']*'|[^\s'">=]+)/,
							inside: {
								'punctuation': [
									{
										pattern: /^=/,
										alias: 'attr-equals'
									},
									{
										pattern: /^(\s*)["']|["']$/,
										lookbehind: true
									}
								]
							}
						},
						'punctuation': /\/?>/,
						'attr-name': {
							pattern: /[^\s>\/]+/,
							inside: {
								'namespace': /^[^\s>\/:]+:/
							}
						}

					}
				},
				'entity': [
					{
						pattern: /&[\da-z]{1,8};/i,
						alias: 'named-entity'
					},
					/&#x?[\da-f]{1,8};/i
				]
			};

			Prism.languages.markup['tag'].inside['attr-value'].inside['entity'] =
				Prism.languages.markup['entity'];
			Prism.languages.markup['doctype'].inside['internal-subset'].inside = Prism.languages.markup;

			// Plugin to make entity title show the real entity, idea by Roman Komarov
			Prism.hooks.add('wrap', function (env) {

				if (env.type === 'entity') {
					env.attributes['title'] = env.content.replace(/&amp;/, '&');
				}
			});

			Object.defineProperty(Prism.languages.markup.tag, 'addInlined', {
				/**
				 * Adds an inlined language to markup.
				 *
				 * An example of an inlined language is CSS with `<style>` tags.
				 *
				 * @param {string} tagName The name of the tag that contains the inlined language. This name will be treated as
				 * case insensitive.
				 * @param {string} lang The language key.
				 * @example
				 * addInlined('style', 'css');
				 */
				value: function addInlined(tagName, lang) {
					var includedCdataInside = {};
					includedCdataInside['language-' + lang] = {
						pattern: /(^<!\[CDATA\[)[\s\S]+?(?=\]\]>$)/i,
						lookbehind: true,
						inside: Prism.languages[lang]
					};
					includedCdataInside['cdata'] = /^<!\[CDATA\[|\]\]>$/i;

					var inside = {
						'included-cdata': {
							pattern: /<!\[CDATA\[[\s\S]*?\]\]>/i,
							inside: includedCdataInside
						}
					};
					inside['language-' + lang] = {
						pattern: /[\s\S]+/,
						inside: Prism.languages[lang]
					};

					var def = {};
					def[tagName] = {
						pattern: RegExp(/(<__[^>]*>)(?:<!\[CDATA\[(?:[^\]]|\](?!\]>))*\]\]>|(?!<!\[CDATA\[)[\s\S])*?(?=<\/__>)/.source.replace(/__/g, function () { return tagName; }), 'i'),
						lookbehind: true,
						greedy: true,
						inside: inside
					};

					Prism.languages.insertBefore('markup', 'cdata', def);
				}
			});
			Object.defineProperty(Prism.languages.markup.tag, 'addAttribute', {
				/**
				 * Adds an pattern to highlight languages embedded in HTML attributes.
				 *
				 * An example of an inlined language is CSS with `style` attributes.
				 *
				 * @param {string} attrName The name of the tag that contains the inlined language. This name will be treated as
				 * case insensitive.
				 * @param {string} lang The language key.
				 * @example
				 * addAttribute('style', 'css');
				 */
				value: function (attrName, lang) {
					Prism.languages.markup.tag.inside['special-attr'].push({
						pattern: RegExp(
							/(^|["'\s])/.source + '(?:' + attrName + ')' + /\s*=\s*(?:"[^"]*"|'[^']*'|[^\s'">=]+(?=[\s>]))/.source,
							'i'
						),
						lookbehind: true,
						inside: {
							'attr-name': /^[^\s=]+/,
							'attr-value': {
								pattern: /=[\s\S]+/,
								inside: {
									'value': {
										pattern: /(^=\s*(["']|(?!["'])))\S[\s\S]*(?=\2$)/,
										lookbehind: true,
										alias: [lang, 'language-' + lang],
										inside: Prism.languages[lang]
									},
									'punctuation': [
										{
											pattern: /^=/,
											alias: 'attr-equals'
										},
										/"|'/
									]
								}
							}
						}
					});
				}
			});

			Prism.languages.html = Prism.languages.markup;
			Prism.languages.mathml = Prism.languages.markup;
			Prism.languages.svg = Prism.languages.markup;

			Prism.languages.xml = Prism.languages.extend('markup', {});
			Prism.languages.ssml = Prism.languages.xml;
			Prism.languages.atom = Prism.languages.xml;
			Prism.languages.rss = Prism.languages.xml;


			/* **********************************************
			     Begin prism-css.js
			********************************************** */

			(function (Prism) {

				var string = /(?:"(?:\\(?:\r\n|[\s\S])|[^"\\\r\n])*"|'(?:\\(?:\r\n|[\s\S])|[^'\\\r\n])*')/;

				Prism.languages.css = {
					'comment': /\/\*[\s\S]*?\*\//,
					'atrule': {
						pattern: RegExp('@[\\w-](?:' + /[^;{\s"']|\s+(?!\s)/.source + '|' + string.source + ')*?' + /(?:;|(?=\s*\{))/.source),
						inside: {
							'rule': /^@[\w-]+/,
							'selector-function-argument': {
								pattern: /(\bselector\s*\(\s*(?![\s)]))(?:[^()\s]|\s+(?![\s)])|\((?:[^()]|\([^()]*\))*\))+(?=\s*\))/,
								lookbehind: true,
								alias: 'selector'
							},
							'keyword': {
								pattern: /(^|[^\w-])(?:and|not|only|or)(?![\w-])/,
								lookbehind: true
							}
							// See rest below
						}
					},
					'url': {
						// https://drafts.csswg.org/css-values-3/#urls
						pattern: RegExp('\\burl\\((?:' + string.source + '|' + /(?:[^\\\r\n()"']|\\[\s\S])*/.source + ')\\)', 'i'),
						greedy: true,
						inside: {
							'function': /^url/i,
							'punctuation': /^\(|\)$/,
							'string': {
								pattern: RegExp('^' + string.source + '$'),
								alias: 'url'
							}
						}
					},
					'selector': {
						pattern: RegExp('(^|[{}\\s])[^{}\\s](?:[^{};"\'\\s]|\\s+(?![\\s{])|' + string.source + ')*(?=\\s*\\{)'),
						lookbehind: true
					},
					'string': {
						pattern: string,
						greedy: true
					},
					'property': {
						pattern: /(^|[^-\w\xA0-\uFFFF])(?!\s)[-_a-z\xA0-\uFFFF](?:(?!\s)[-\w\xA0-\uFFFF])*(?=\s*:)/i,
						lookbehind: true
					},
					'important': /!important\b/i,
					'function': {
						pattern: /(^|[^-a-z0-9])[-a-z0-9]+(?=\()/i,
						lookbehind: true
					},
					'punctuation': /[(){};:,]/
				};

				Prism.languages.css['atrule'].inside.rest = Prism.languages.css;

				var markup = Prism.languages.markup;
				if (markup) {
					markup.tag.addInlined('style', 'css');
					markup.tag.addAttribute('style', 'css');
				}

			}(Prism));


			/* **********************************************
			     Begin prism-clike.js
			********************************************** */

			Prism.languages.clike = {
				'comment': [
					{
						pattern: /(^|[^\\])\/\*[\s\S]*?(?:\*\/|$)/,
						lookbehind: true,
						greedy: true
					},
					{
						pattern: /(^|[^\\:])\/\/.*/,
						lookbehind: true,
						greedy: true
					}
				],
				'string': {
					pattern: /(["'])(?:\\(?:\r\n|[\s\S])|(?!\1)[^\\\r\n])*\1/,
					greedy: true
				},
				'class-name': {
					pattern: /(\b(?:class|extends|implements|instanceof|interface|new|trait)\s+|\bcatch\s+\()[\w.\\]+/i,
					lookbehind: true,
					inside: {
						'punctuation': /[.\\]/
					}
				},
				'keyword': /\b(?:break|catch|continue|do|else|finally|for|function|if|in|instanceof|new|null|return|throw|try|while)\b/,
				'boolean': /\b(?:false|true)\b/,
				'function': /\b\w+(?=\()/,
				'number': /\b0x[\da-f]+\b|(?:\b\d+(?:\.\d*)?|\B\.\d+)(?:e[+-]?\d+)?/i,
				'operator': /[<>]=?|[!=]=?=?|--?|\+\+?|&&?|\|\|?|[?*/~^%]/,
				'punctuation': /[{}[\];(),.:]/
			};


			/* **********************************************
			     Begin prism-javascript.js
			********************************************** */

			Prism.languages.javascript = Prism.languages.extend('clike', {
				'class-name': [
					Prism.languages.clike['class-name'],
					{
						pattern: /(^|[^$\w\xA0-\uFFFF])(?!\s)[_$A-Z\xA0-\uFFFF](?:(?!\s)[$\w\xA0-\uFFFF])*(?=\.(?:constructor|prototype))/,
						lookbehind: true
					}
				],
				'keyword': [
					{
						pattern: /((?:^|\})\s*)catch\b/,
						lookbehind: true
					},
					{
						pattern: /(^|[^.]|\.\.\.\s*)\b(?:as|assert(?=\s*\{)|async(?=\s*(?:function\b|\(|[$\w\xA0-\uFFFF]|$))|await|break|case|class|const|continue|debugger|default|delete|do|else|enum|export|extends|finally(?=\s*(?:\{|$))|for|from(?=\s*(?:['"]|$))|function|(?:get|set)(?=\s*(?:[#\[$\w\xA0-\uFFFF]|$))|if|implements|import|in|instanceof|interface|let|new|null|of|package|private|protected|public|return|static|super|switch|this|throw|try|typeof|undefined|var|void|while|with|yield)\b/,
						lookbehind: true
					},
				],
				// Allow for all non-ASCII characters (See http://stackoverflow.com/a/2008444)
				'function': /#?(?!\s)[_$a-zA-Z\xA0-\uFFFF](?:(?!\s)[$\w\xA0-\uFFFF])*(?=\s*(?:\.\s*(?:apply|bind|call)\s*)?\()/,
				'number': {
					pattern: RegExp(
						/(^|[^\w$])/.source +
						'(?:' +
						(
							// constant
							/NaN|Infinity/.source +
							'|' +
							// binary integer
							/0[bB][01]+(?:_[01]+)*n?/.source +
							'|' +
							// octal integer
							/0[oO][0-7]+(?:_[0-7]+)*n?/.source +
							'|' +
							// hexadecimal integer
							/0[xX][\dA-Fa-f]+(?:_[\dA-Fa-f]+)*n?/.source +
							'|' +
							// decimal bigint
							/\d+(?:_\d+)*n/.source +
							'|' +
							// decimal number (integer or float) but no bigint
							/(?:\d+(?:_\d+)*(?:\.(?:\d+(?:_\d+)*)?)?|\.\d+(?:_\d+)*)(?:[Ee][+-]?\d+(?:_\d+)*)?/.source
						) +
						')' +
						/(?![\w$])/.source
					),
					lookbehind: true
				},
				'operator': /--|\+\+|\*\*=?|=>|&&=?|\|\|=?|[!=]==|<<=?|>>>?=?|[-+*/%&|^!=<>]=?|\.{3}|\?\?=?|\?\.?|[~:]/
			});

			Prism.languages.javascript['class-name'][0].pattern = /(\b(?:class|extends|implements|instanceof|interface|new)\s+)[\w.\\]+/;

			Prism.languages.insertBefore('javascript', 'keyword', {
				'regex': {
					pattern: RegExp(
						// lookbehind
						// eslint-disable-next-line regexp/no-dupe-characters-character-class
						/((?:^|[^$\w\xA0-\uFFFF."'\])\s]|\b(?:return|yield))\s*)/.source +
						// Regex pattern:
						// There are 2 regex patterns here. The RegExp set notation proposal added support for nested character
						// classes if the `v` flag is present. Unfortunately, nested CCs are both context-free and incompatible
						// with the only syntax, so we have to define 2 different regex patterns.
						/\//.source +
						'(?:' +
						/(?:\[(?:[^\]\\\r\n]|\\.)*\]|\\.|[^/\\\[\r\n])+\/[dgimyus]{0,7}/.source +
						'|' +
						// `v` flag syntax. This supports 3 levels of nested character classes.
						/(?:\[(?:[^[\]\\\r\n]|\\.|\[(?:[^[\]\\\r\n]|\\.|\[(?:[^[\]\\\r\n]|\\.)*\])*\])*\]|\\.|[^/\\\[\r\n])+\/[dgimyus]{0,7}v[dgimyus]{0,7}/.source +
						')' +
						// lookahead
						/(?=(?:\s|\/\*(?:[^*]|\*(?!\/))*\*\/)*(?:$|[\r\n,.;:})\]]|\/\/))/.source
					),
					lookbehind: true,
					greedy: true,
					inside: {
						'regex-source': {
							pattern: /^(\/)[\s\S]+(?=\/[a-z]*$)/,
							lookbehind: true,
							alias: 'language-regex',
							inside: Prism.languages.regex
						},
						'regex-delimiter': /^\/|\/$/,
						'regex-flags': /^[a-z]+$/,
					}
				},
				// This must be declared before keyword because we use "function" inside the look-forward
				'function-variable': {
					pattern: /#?(?!\s)[_$a-zA-Z\xA0-\uFFFF](?:(?!\s)[$\w\xA0-\uFFFF])*(?=\s*[=:]\s*(?:async\s*)?(?:\bfunction\b|(?:\((?:[^()]|\([^()]*\))*\)|(?!\s)[_$a-zA-Z\xA0-\uFFFF](?:(?!\s)[$\w\xA0-\uFFFF])*)\s*=>))/,
					alias: 'function'
				},
				'parameter': [
					{
						pattern: /(function(?:\s+(?!\s)[_$a-zA-Z\xA0-\uFFFF](?:(?!\s)[$\w\xA0-\uFFFF])*)?\s*\(\s*)(?!\s)(?:[^()\s]|\s+(?![\s)])|\([^()]*\))+(?=\s*\))/,
						lookbehind: true,
						inside: Prism.languages.javascript
					},
					{
						pattern: /(^|[^$\w\xA0-\uFFFF])(?!\s)[_$a-z\xA0-\uFFFF](?:(?!\s)[$\w\xA0-\uFFFF])*(?=\s*=>)/i,
						lookbehind: true,
						inside: Prism.languages.javascript
					},
					{
						pattern: /(\(\s*)(?!\s)(?:[^()\s]|\s+(?![\s)])|\([^()]*\))+(?=\s*\)\s*=>)/,
						lookbehind: true,
						inside: Prism.languages.javascript
					},
					{
						pattern: /((?:\b|\s|^)(?!(?:as|async|await|break|case|catch|class|const|continue|debugger|default|delete|do|else|enum|export|extends|finally|for|from|function|get|if|implements|import|in|instanceof|interface|let|new|null|of|package|private|protected|public|return|set|static|super|switch|this|throw|try|typeof|undefined|var|void|while|with|yield)(?![$\w\xA0-\uFFFF]))(?:(?!\s)[_$a-zA-Z\xA0-\uFFFF](?:(?!\s)[$\w\xA0-\uFFFF])*\s*)\(\s*|\]\s*\(\s*)(?!\s)(?:[^()\s]|\s+(?![\s)])|\([^()]*\))+(?=\s*\)\s*\{)/,
						lookbehind: true,
						inside: Prism.languages.javascript
					}
				],
				'constant': /\b[A-Z](?:[A-Z_]|\dx?)*\b/
			});

			Prism.languages.insertBefore('javascript', 'string', {
				'hashbang': {
					pattern: /^#!.*/,
					greedy: true,
					alias: 'comment'
				},
				'template-string': {
					pattern: /`(?:\\[\s\S]|\$\{(?:[^{}]|\{(?:[^{}]|\{[^}]*\})*\})+\}|(?!\$\{)[^\\`])*`/,
					greedy: true,
					inside: {
						'template-punctuation': {
							pattern: /^`|`$/,
							alias: 'string'
						},
						'interpolation': {
							pattern: /((?:^|[^\\])(?:\\{2})*)\$\{(?:[^{}]|\{(?:[^{}]|\{[^}]*\})*\})+\}/,
							lookbehind: true,
							inside: {
								'interpolation-punctuation': {
									pattern: /^\$\{|\}$/,
									alias: 'punctuation'
								},
								rest: Prism.languages.javascript
							}
						},
						'string': /[\s\S]+/
					}
				},
				'string-property': {
					pattern: /((?:^|[,{])[ \t]*)(["'])(?:\\(?:\r\n|[\s\S])|(?!\2)[^\\\r\n])*\2(?=\s*:)/m,
					lookbehind: true,
					greedy: true,
					alias: 'property'
				}
			});

			Prism.languages.insertBefore('javascript', 'operator', {
				'literal-property': {
					pattern: /((?:^|[,{])[ \t]*)(?!\s)[_$a-zA-Z\xA0-\uFFFF](?:(?!\s)[$\w\xA0-\uFFFF])*(?=\s*:)/m,
					lookbehind: true,
					alias: 'property'
				},
			});

			if (Prism.languages.markup) {
				Prism.languages.markup.tag.addInlined('script', 'javascript');

				// add attribute support for all DOM events.
				// https://developer.mozilla.org/en-US/docs/Web/Events#Standard_events
				Prism.languages.markup.tag.addAttribute(
					/on(?:abort|blur|change|click|composition(?:end|start|update)|dblclick|error|focus(?:in|out)?|key(?:down|up)|load|mouse(?:down|enter|leave|move|out|over|up)|reset|resize|scroll|select|slotchange|submit|unload|wheel)/.source,
					'javascript'
				);
			}

			Prism.languages.js = Prism.languages.javascript;


			/* **********************************************
			     Begin prism-file-highlight.js
			********************************************** */

			(function () {

				if (typeof Prism === 'undefined' || typeof document === 'undefined') {
					return;
				}

				// https://developer.mozilla.org/en-US/docs/Web/API/Element/matches#Polyfill
				if (!Element.prototype.matches) {
					Element.prototype.matches = Element.prototype.msMatchesSelector || Element.prototype.webkitMatchesSelector;
				}

				var LOADING_MESSAGE = 'Loading…';
				var FAILURE_MESSAGE = function (status, message) {
					return '✖ Error ' + status + ' while fetching file: ' + message;
				};
				var FAILURE_EMPTY_MESSAGE = '✖ Error: File does not exist or is empty';

				var EXTENSIONS = {
					'js': 'javascript',
					'py': 'python',
					'rb': 'ruby',
					'ps1': 'powershell',
					'psm1': 'powershell',
					'sh': 'bash',
					'bat': 'batch',
					'h': 'c',
					'tex': 'latex'
				};

				var STATUS_ATTR = 'data-src-status';
				var STATUS_LOADING = 'loading';
				var STATUS_LOADED = 'loaded';
				var STATUS_FAILED = 'failed';

				var SELECTOR = 'pre[data-src]:not([' + STATUS_ATTR + '="' + STATUS_LOADED + '"])'
					+ ':not([' + STATUS_ATTR + '="' + STATUS_LOADING + '"])';

				/**
				 * Loads the given file.
				 *
				 * @param {string} src The URL or path of the source file to load.
				 * @param {(result: string) => void} success
				 * @param {(reason: string) => void} error
				 */
				function loadFile(src, success, error) {
					var xhr = new XMLHttpRequest();
					xhr.open('GET', src, true);
					xhr.onreadystatechange = function () {
						if (xhr.readyState == 4) {
							if (xhr.status < 400 && xhr.responseText) {
								success(xhr.responseText);
							} else {
								if (xhr.status >= 400) {
									error(FAILURE_MESSAGE(xhr.status, xhr.statusText));
								} else {
									error(FAILURE_EMPTY_MESSAGE);
								}
							}
						}
					};
					xhr.send(null);
				}

				/**
				 * Parses the given range.
				 *
				 * This returns a range with inclusive ends.
				 *
				 * @param {string | null | undefined} range
				 * @returns {[number, number | undefined] | undefined}
				 */
				function parseRange(range) {
					var m = /^\s*(\d+)\s*(?:(,)\s*(?:(\d+)\s*)?)?$/.exec(range || '');
					if (m) {
						var start = Number(m[1]);
						var comma = m[2];
						var end = m[3];

						if (!comma) {
							return [start, start];
						}
						if (!end) {
							return [start, undefined];
						}
						return [start, Number(end)];
					}
					return undefined;
				}

				Prism.hooks.add('before-highlightall', function (env) {
					env.selector += ', ' + SELECTOR;
				});

				Prism.hooks.add('before-sanity-check', function (env) {
					var pre = /** @type {HTMLPreElement} */ (env.element);
					if (pre.matches(SELECTOR)) {
						env.code = ''; // fast-path the whole thing and go to complete

						pre.setAttribute(STATUS_ATTR, STATUS_LOADING); // mark as loading

						// add code element with loading message
						var code = pre.appendChild(document.createElement('CODE'));
						code.textContent = LOADING_MESSAGE;

						var src = pre.getAttribute('data-src');

						var language = env.language;
						if (language === 'none') {
							// the language might be 'none' because there is no language set;
							// in this case, we want to use the extension as the language
							var extension = (/\.(\w+)$/.exec(src) || [, 'none'])[1];
							language = EXTENSIONS[extension] || extension;
						}

						// set language classes
						Prism.util.setLanguage(code, language);
						Prism.util.setLanguage(pre, language);

						// preload the language
						var autoloader = Prism.plugins.autoloader;
						if (autoloader) {
							autoloader.loadLanguages(language);
						}

						// load file
						loadFile(
							src,
							function (text) {
								// mark as loaded
								pre.setAttribute(STATUS_ATTR, STATUS_LOADED);

								// handle data-range
								var range = parseRange(pre.getAttribute('data-range'));
								if (range) {
									var lines = text.split(/\r\n?|\n/g);

									// the range is one-based and inclusive on both ends
									var start = range[0];
									var end = range[1] == null ? lines.length : range[1];

									if (start < 0) { start += lines.length; }
									start = Math.max(0, Math.min(start - 1, lines.length));
									if (end < 0) { end += lines.length; }
									end = Math.max(0, Math.min(end, lines.length));

									text = lines.slice(start, end).join('\n');

									// add data-start for line numbers
									if (!pre.hasAttribute('data-start')) {
										pre.setAttribute('data-start', String(start + 1));
									}
								}

								// highlight code
								code.textContent = text;
								Prism.highlightElement(code);
							},
							function (error) {
								// mark as failed
								pre.setAttribute(STATUS_ATTR, STATUS_FAILED);

								code.textContent = error;
							}
						);
					}
				});

				Prism.plugins.fileHighlight = {
					/**
					 * Executes the File Highlight plugin for all matching `pre` elements under the given container.
					 *
					 * Note: Elements which are already loaded or currently loading will not be touched by this method.
					 *
					 * @param {ParentNode} [container=document]
					 */
					highlight: function highlight(container) {
						var elements = (container || document).querySelectorAll(SELECTOR);

						for (var i = 0, element; (element = elements[i++]);) {
							Prism.highlightElement(element);
						}
					}
				};

				var logged = false;
				/** @deprecated Use `Prism.plugins.fileHighlight.highlight` instead. */
				Prism.fileHighlight = function () {
					if (!logged) {
						console.warn('Prism.fileHighlight is deprecated. Use `Prism.plugins.fileHighlight.highlight` instead.');
						logged = true;
					}
					Prism.plugins.fileHighlight.highlight.apply(this, arguments);
				};

			}()); 
		} (prism));
		return prism.exports;
	}

	requirePrism();

	Prism.languages.clike = {
		'comment': [
			{
				pattern: /(^|[^\\])\/\*[\s\S]*?(?:\*\/|$)/,
				lookbehind: true,
				greedy: true
			},
			{
				pattern: /(^|[^\\:])\/\/.*/,
				lookbehind: true,
				greedy: true
			}
		],
		'string': {
			pattern: /(["'])(?:\\(?:\r\n|[\s\S])|(?!\1)[^\\\r\n])*\1/,
			greedy: true
		},
		'class-name': {
			pattern: /(\b(?:class|extends|implements|instanceof|interface|new|trait)\s+|\bcatch\s+\()[\w.\\]+/i,
			lookbehind: true,
			inside: {
				'punctuation': /[.\\]/
			}
		},
		'keyword': /\b(?:break|catch|continue|do|else|finally|for|function|if|in|instanceof|new|null|return|throw|try|while)\b/,
		'boolean': /\b(?:false|true)\b/,
		'function': /\b\w+(?=\()/,
		'number': /\b0x[\da-f]+\b|(?:\b\d+(?:\.\d*)?|\B\.\d+)(?:e[+-]?\d+)?/i,
		'operator': /[<>]=?|[!=]=?=?|--?|\+\+?|&&?|\|\|?|[?*/~^%]/,
		'punctuation': /[{}[\];(),.:]/
	};

	Prism.languages.javascript = Prism.languages.extend('clike', {
		'class-name': [
			Prism.languages.clike['class-name'],
			{
				pattern: /(^|[^$\w\xA0-\uFFFF])(?!\s)[_$A-Z\xA0-\uFFFF](?:(?!\s)[$\w\xA0-\uFFFF])*(?=\.(?:constructor|prototype))/,
				lookbehind: true
			}
		],
		'keyword': [
			{
				pattern: /((?:^|\})\s*)catch\b/,
				lookbehind: true
			},
			{
				pattern: /(^|[^.]|\.\.\.\s*)\b(?:as|assert(?=\s*\{)|async(?=\s*(?:function\b|\(|[$\w\xA0-\uFFFF]|$))|await|break|case|class|const|continue|debugger|default|delete|do|else|enum|export|extends|finally(?=\s*(?:\{|$))|for|from(?=\s*(?:['"]|$))|function|(?:get|set)(?=\s*(?:[#\[$\w\xA0-\uFFFF]|$))|if|implements|import|in|instanceof|interface|let|new|null|of|package|private|protected|public|return|static|super|switch|this|throw|try|typeof|undefined|var|void|while|with|yield)\b/,
				lookbehind: true
			},
		],
		// Allow for all non-ASCII characters (See http://stackoverflow.com/a/2008444)
		'function': /#?(?!\s)[_$a-zA-Z\xA0-\uFFFF](?:(?!\s)[$\w\xA0-\uFFFF])*(?=\s*(?:\.\s*(?:apply|bind|call)\s*)?\()/,
		'number': {
			pattern: RegExp(
				/(^|[^\w$])/.source +
				'(?:' +
				(
					// constant
					/NaN|Infinity/.source +
					'|' +
					// binary integer
					/0[bB][01]+(?:_[01]+)*n?/.source +
					'|' +
					// octal integer
					/0[oO][0-7]+(?:_[0-7]+)*n?/.source +
					'|' +
					// hexadecimal integer
					/0[xX][\dA-Fa-f]+(?:_[\dA-Fa-f]+)*n?/.source +
					'|' +
					// decimal bigint
					/\d+(?:_\d+)*n/.source +
					'|' +
					// decimal number (integer or float) but no bigint
					/(?:\d+(?:_\d+)*(?:\.(?:\d+(?:_\d+)*)?)?|\.\d+(?:_\d+)*)(?:[Ee][+-]?\d+(?:_\d+)*)?/.source
				) +
				')' +
				/(?![\w$])/.source
			),
			lookbehind: true
		},
		'operator': /--|\+\+|\*\*=?|=>|&&=?|\|\|=?|[!=]==|<<=?|>>>?=?|[-+*/%&|^!=<>]=?|\.{3}|\?\?=?|\?\.?|[~:]/
	});

	Prism.languages.javascript['class-name'][0].pattern = /(\b(?:class|extends|implements|instanceof|interface|new)\s+)[\w.\\]+/;

	Prism.languages.insertBefore('javascript', 'keyword', {
		'regex': {
			pattern: RegExp(
				// lookbehind
				// eslint-disable-next-line regexp/no-dupe-characters-character-class
				/((?:^|[^$\w\xA0-\uFFFF."'\])\s]|\b(?:return|yield))\s*)/.source +
				// Regex pattern:
				// There are 2 regex patterns here. The RegExp set notation proposal added support for nested character
				// classes if the `v` flag is present. Unfortunately, nested CCs are both context-free and incompatible
				// with the only syntax, so we have to define 2 different regex patterns.
				/\//.source +
				'(?:' +
				/(?:\[(?:[^\]\\\r\n]|\\.)*\]|\\.|[^/\\\[\r\n])+\/[dgimyus]{0,7}/.source +
				'|' +
				// `v` flag syntax. This supports 3 levels of nested character classes.
				/(?:\[(?:[^[\]\\\r\n]|\\.|\[(?:[^[\]\\\r\n]|\\.|\[(?:[^[\]\\\r\n]|\\.)*\])*\])*\]|\\.|[^/\\\[\r\n])+\/[dgimyus]{0,7}v[dgimyus]{0,7}/.source +
				')' +
				// lookahead
				/(?=(?:\s|\/\*(?:[^*]|\*(?!\/))*\*\/)*(?:$|[\r\n,.;:})\]]|\/\/))/.source
			),
			lookbehind: true,
			greedy: true,
			inside: {
				'regex-source': {
					pattern: /^(\/)[\s\S]+(?=\/[a-z]*$)/,
					lookbehind: true,
					alias: 'language-regex',
					inside: Prism.languages.regex
				},
				'regex-delimiter': /^\/|\/$/,
				'regex-flags': /^[a-z]+$/,
			}
		},
		// This must be declared before keyword because we use "function" inside the look-forward
		'function-variable': {
			pattern: /#?(?!\s)[_$a-zA-Z\xA0-\uFFFF](?:(?!\s)[$\w\xA0-\uFFFF])*(?=\s*[=:]\s*(?:async\s*)?(?:\bfunction\b|(?:\((?:[^()]|\([^()]*\))*\)|(?!\s)[_$a-zA-Z\xA0-\uFFFF](?:(?!\s)[$\w\xA0-\uFFFF])*)\s*=>))/,
			alias: 'function'
		},
		'parameter': [
			{
				pattern: /(function(?:\s+(?!\s)[_$a-zA-Z\xA0-\uFFFF](?:(?!\s)[$\w\xA0-\uFFFF])*)?\s*\(\s*)(?!\s)(?:[^()\s]|\s+(?![\s)])|\([^()]*\))+(?=\s*\))/,
				lookbehind: true,
				inside: Prism.languages.javascript
			},
			{
				pattern: /(^|[^$\w\xA0-\uFFFF])(?!\s)[_$a-z\xA0-\uFFFF](?:(?!\s)[$\w\xA0-\uFFFF])*(?=\s*=>)/i,
				lookbehind: true,
				inside: Prism.languages.javascript
			},
			{
				pattern: /(\(\s*)(?!\s)(?:[^()\s]|\s+(?![\s)])|\([^()]*\))+(?=\s*\)\s*=>)/,
				lookbehind: true,
				inside: Prism.languages.javascript
			},
			{
				pattern: /((?:\b|\s|^)(?!(?:as|async|await|break|case|catch|class|const|continue|debugger|default|delete|do|else|enum|export|extends|finally|for|from|function|get|if|implements|import|in|instanceof|interface|let|new|null|of|package|private|protected|public|return|set|static|super|switch|this|throw|try|typeof|undefined|var|void|while|with|yield)(?![$\w\xA0-\uFFFF]))(?:(?!\s)[_$a-zA-Z\xA0-\uFFFF](?:(?!\s)[$\w\xA0-\uFFFF])*\s*)\(\s*|\]\s*\(\s*)(?!\s)(?:[^()\s]|\s+(?![\s)])|\([^()]*\))+(?=\s*\)\s*\{)/,
				lookbehind: true,
				inside: Prism.languages.javascript
			}
		],
		'constant': /\b[A-Z](?:[A-Z_]|\dx?)*\b/
	});

	Prism.languages.insertBefore('javascript', 'string', {
		'hashbang': {
			pattern: /^#!.*/,
			greedy: true,
			alias: 'comment'
		},
		'template-string': {
			pattern: /`(?:\\[\s\S]|\$\{(?:[^{}]|\{(?:[^{}]|\{[^}]*\})*\})+\}|(?!\$\{)[^\\`])*`/,
			greedy: true,
			inside: {
				'template-punctuation': {
					pattern: /^`|`$/,
					alias: 'string'
				},
				'interpolation': {
					pattern: /((?:^|[^\\])(?:\\{2})*)\$\{(?:[^{}]|\{(?:[^{}]|\{[^}]*\})*\})+\}/,
					lookbehind: true,
					inside: {
						'interpolation-punctuation': {
							pattern: /^\$\{|\}$/,
							alias: 'punctuation'
						},
						rest: Prism.languages.javascript
					}
				},
				'string': /[\s\S]+/
			}
		},
		'string-property': {
			pattern: /((?:^|[,{])[ \t]*)(["'])(?:\\(?:\r\n|[\s\S])|(?!\2)[^\\\r\n])*\2(?=\s*:)/m,
			lookbehind: true,
			greedy: true,
			alias: 'property'
		}
	});

	Prism.languages.insertBefore('javascript', 'operator', {
		'literal-property': {
			pattern: /((?:^|[,{])[ \t]*)(?!\s)[_$a-zA-Z\xA0-\uFFFF](?:(?!\s)[$\w\xA0-\uFFFF])*(?=\s*:)/m,
			lookbehind: true,
			alias: 'property'
		},
	});

	if (Prism.languages.markup) {
		Prism.languages.markup.tag.addInlined('script', 'javascript');

		// add attribute support for all DOM events.
		// https://developer.mozilla.org/en-US/docs/Web/Events#Standard_events
		Prism.languages.markup.tag.addAttribute(
			/on(?:abort|blur|change|click|composition(?:end|start|update)|dblclick|error|focus(?:in|out)?|key(?:down|up)|load|mouse(?:down|enter|leave|move|out|over|up)|reset|resize|scroll|select|slotchange|submit|unload|wheel)/.source,
			'javascript'
		);
	}

	Prism.languages.js = Prism.languages.javascript;

	Prism.languages.markup = {
		'comment': {
			pattern: /<!--(?:(?!<!--)[\s\S])*?-->/,
			greedy: true
		},
		'prolog': {
			pattern: /<\?[\s\S]+?\?>/,
			greedy: true
		},
		'doctype': {
			// https://www.w3.org/TR/xml/#NT-doctypedecl
			pattern: /<!DOCTYPE(?:[^>"'[\]]|"[^"]*"|'[^']*')+(?:\[(?:[^<"'\]]|"[^"]*"|'[^']*'|<(?!!--)|<!--(?:[^-]|-(?!->))*-->)*\]\s*)?>/i,
			greedy: true,
			inside: {
				'internal-subset': {
					pattern: /(^[^\[]*\[)[\s\S]+(?=\]>$)/,
					lookbehind: true,
					greedy: true,
					inside: null // see below
				},
				'string': {
					pattern: /"[^"]*"|'[^']*'/,
					greedy: true
				},
				'punctuation': /^<!|>$|[[\]]/,
				'doctype-tag': /^DOCTYPE/i,
				'name': /[^\s<>'"]+/
			}
		},
		'cdata': {
			pattern: /<!\[CDATA\[[\s\S]*?\]\]>/i,
			greedy: true
		},
		'tag': {
			pattern: /<\/?(?!\d)[^\s>\/=$<%]+(?:\s(?:\s*[^\s>\/=]+(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s'">=]+(?=[\s>]))|(?=[\s/>])))+)?\s*\/?>/,
			greedy: true,
			inside: {
				'tag': {
					pattern: /^<\/?[^\s>\/]+/,
					inside: {
						'punctuation': /^<\/?/,
						'namespace': /^[^\s>\/:]+:/
					}
				},
				'special-attr': [],
				'attr-value': {
					pattern: /=\s*(?:"[^"]*"|'[^']*'|[^\s'">=]+)/,
					inside: {
						'punctuation': [
							{
								pattern: /^=/,
								alias: 'attr-equals'
							},
							{
								pattern: /^(\s*)["']|["']$/,
								lookbehind: true
							}
						]
					}
				},
				'punctuation': /\/?>/,
				'attr-name': {
					pattern: /[^\s>\/]+/,
					inside: {
						'namespace': /^[^\s>\/:]+:/
					}
				}

			}
		},
		'entity': [
			{
				pattern: /&[\da-z]{1,8};/i,
				alias: 'named-entity'
			},
			/&#x?[\da-f]{1,8};/i
		]
	};

	Prism.languages.markup['tag'].inside['attr-value'].inside['entity'] =
		Prism.languages.markup['entity'];
	Prism.languages.markup['doctype'].inside['internal-subset'].inside = Prism.languages.markup;

	// Plugin to make entity title show the real entity, idea by Roman Komarov
	Prism.hooks.add('wrap', function (env) {

		if (env.type === 'entity') {
			env.attributes['title'] = env.content.replace(/&amp;/, '&');
		}
	});

	Object.defineProperty(Prism.languages.markup.tag, 'addInlined', {
		/**
		 * Adds an inlined language to markup.
		 *
		 * An example of an inlined language is CSS with `<style>` tags.
		 *
		 * @param {string} tagName The name of the tag that contains the inlined language. This name will be treated as
		 * case insensitive.
		 * @param {string} lang The language key.
		 * @example
		 * addInlined('style', 'css');
		 */
		value: function addInlined(tagName, lang) {
			var includedCdataInside = {};
			includedCdataInside['language-' + lang] = {
				pattern: /(^<!\[CDATA\[)[\s\S]+?(?=\]\]>$)/i,
				lookbehind: true,
				inside: Prism.languages[lang]
			};
			includedCdataInside['cdata'] = /^<!\[CDATA\[|\]\]>$/i;

			var inside = {
				'included-cdata': {
					pattern: /<!\[CDATA\[[\s\S]*?\]\]>/i,
					inside: includedCdataInside
				}
			};
			inside['language-' + lang] = {
				pattern: /[\s\S]+/,
				inside: Prism.languages[lang]
			};

			var def = {};
			def[tagName] = {
				pattern: RegExp(/(<__[^>]*>)(?:<!\[CDATA\[(?:[^\]]|\](?!\]>))*\]\]>|(?!<!\[CDATA\[)[\s\S])*?(?=<\/__>)/.source.replace(/__/g, function () { return tagName; }), 'i'),
				lookbehind: true,
				greedy: true,
				inside: inside
			};

			Prism.languages.insertBefore('markup', 'cdata', def);
		}
	});
	Object.defineProperty(Prism.languages.markup.tag, 'addAttribute', {
		/**
		 * Adds an pattern to highlight languages embedded in HTML attributes.
		 *
		 * An example of an inlined language is CSS with `style` attributes.
		 *
		 * @param {string} attrName The name of the tag that contains the inlined language. This name will be treated as
		 * case insensitive.
		 * @param {string} lang The language key.
		 * @example
		 * addAttribute('style', 'css');
		 */
		value: function (attrName, lang) {
			Prism.languages.markup.tag.inside['special-attr'].push({
				pattern: RegExp(
					/(^|["'\s])/.source + '(?:' + attrName + ')' + /\s*=\s*(?:"[^"]*"|'[^']*'|[^\s'">=]+(?=[\s>]))/.source,
					'i'
				),
				lookbehind: true,
				inside: {
					'attr-name': /^[^\s=]+/,
					'attr-value': {
						pattern: /=[\s\S]+/,
						inside: {
							'value': {
								pattern: /(^=\s*(["']|(?!["'])))\S[\s\S]*(?=\2$)/,
								lookbehind: true,
								alias: [lang, 'language-' + lang],
								inside: Prism.languages[lang]
							},
							'punctuation': [
								{
									pattern: /^=/,
									alias: 'attr-equals'
								},
								/"|'/
							]
						}
					}
				}
			});
		}
	});

	Prism.languages.html = Prism.languages.markup;
	Prism.languages.mathml = Prism.languages.markup;
	Prism.languages.svg = Prism.languages.markup;

	Prism.languages.xml = Prism.languages.extend('markup', {});
	Prism.languages.ssml = Prism.languages.xml;
	Prism.languages.atom = Prism.languages.xml;
	Prism.languages.rss = Prism.languages.xml;

	(function (Prism) {

		// Allow only one line break
		var inner = /(?:\\.|[^\\\n\r]|(?:\n|\r\n?)(?![\r\n]))/.source;

		/**
		 * This function is intended for the creation of the bold or italic pattern.
		 *
		 * This also adds a lookbehind group to the given pattern to ensure that the pattern is not backslash-escaped.
		 *
		 * _Note:_ Keep in mind that this adds a capturing group.
		 *
		 * @param {string} pattern
		 * @returns {RegExp}
		 */
		function createInline(pattern) {
			pattern = pattern.replace(/<inner>/g, function () { return inner; });
			return RegExp(/((?:^|[^\\])(?:\\{2})*)/.source + '(?:' + pattern + ')');
		}


		var tableCell = /(?:\\.|``(?:[^`\r\n]|`(?!`))+``|`[^`\r\n]+`|[^\\|\r\n`])+/.source;
		var tableRow = /\|?__(?:\|__)+\|?(?:(?:\n|\r\n?)|(?![\s\S]))/.source.replace(/__/g, function () { return tableCell; });
		var tableLine = /\|?[ \t]*:?-{3,}:?[ \t]*(?:\|[ \t]*:?-{3,}:?[ \t]*)+\|?(?:\n|\r\n?)/.source;


		Prism.languages.markdown = Prism.languages.extend('markup', {});
		Prism.languages.insertBefore('markdown', 'prolog', {
			'front-matter-block': {
				pattern: /(^(?:\s*[\r\n])?)---(?!.)[\s\S]*?[\r\n]---(?!.)/,
				lookbehind: true,
				greedy: true,
				inside: {
					'punctuation': /^---|---$/,
					'front-matter': {
						pattern: /\S+(?:\s+\S+)*/,
						alias: ['yaml', 'language-yaml'],
						inside: Prism.languages.yaml
					}
				}
			},
			'blockquote': {
				// > ...
				pattern: /^>(?:[\t ]*>)*/m,
				alias: 'punctuation'
			},
			'table': {
				pattern: RegExp('^' + tableRow + tableLine + '(?:' + tableRow + ')*', 'm'),
				inside: {
					'table-data-rows': {
						pattern: RegExp('^(' + tableRow + tableLine + ')(?:' + tableRow + ')*$'),
						lookbehind: true,
						inside: {
							'table-data': {
								pattern: RegExp(tableCell),
								inside: Prism.languages.markdown
							},
							'punctuation': /\|/
						}
					},
					'table-line': {
						pattern: RegExp('^(' + tableRow + ')' + tableLine + '$'),
						lookbehind: true,
						inside: {
							'punctuation': /\||:?-{3,}:?/
						}
					},
					'table-header-row': {
						pattern: RegExp('^' + tableRow + '$'),
						inside: {
							'table-header': {
								pattern: RegExp(tableCell),
								alias: 'important',
								inside: Prism.languages.markdown
							},
							'punctuation': /\|/
						}
					}
				}
			},
			'code': [
				{
					// Prefixed by 4 spaces or 1 tab and preceded by an empty line
					pattern: /((?:^|\n)[ \t]*\n|(?:^|\r\n?)[ \t]*\r\n?)(?: {4}|\t).+(?:(?:\n|\r\n?)(?: {4}|\t).+)*/,
					lookbehind: true,
					alias: 'keyword'
				},
				{
					// ```optional language
					// code block
					// ```
					pattern: /^```[\s\S]*?^```$/m,
					greedy: true,
					inside: {
						'code-block': {
							pattern: /^(```.*(?:\n|\r\n?))[\s\S]+?(?=(?:\n|\r\n?)^```$)/m,
							lookbehind: true
						},
						'code-language': {
							pattern: /^(```).+/,
							lookbehind: true
						},
						'punctuation': /```/
					}
				}
			],
			'title': [
				{
					// title 1
					// =======

					// title 2
					// -------
					pattern: /\S.*(?:\n|\r\n?)(?:==+|--+)(?=[ \t]*$)/m,
					alias: 'important',
					inside: {
						punctuation: /==+$|--+$/
					}
				},
				{
					// # title 1
					// ###### title 6
					pattern: /(^\s*)#.+/m,
					lookbehind: true,
					alias: 'important',
					inside: {
						punctuation: /^#+|#+$/
					}
				}
			],
			'hr': {
				// ***
				// ---
				// * * *
				// -----------
				pattern: /(^\s*)([*-])(?:[\t ]*\2){2,}(?=\s*$)/m,
				lookbehind: true,
				alias: 'punctuation'
			},
			'list': {
				// * item
				// + item
				// - item
				// 1. item
				pattern: /(^\s*)(?:[*+-]|\d+\.)(?=[\t ].)/m,
				lookbehind: true,
				alias: 'punctuation'
			},
			'url-reference': {
				// [id]: http://example.com "Optional title"
				// [id]: http://example.com 'Optional title'
				// [id]: http://example.com (Optional title)
				// [id]: <http://example.com> "Optional title"
				pattern: /!?\[[^\]]+\]:[\t ]+(?:\S+|<(?:\\.|[^>\\])+>)(?:[\t ]+(?:"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|\((?:\\.|[^)\\])*\)))?/,
				inside: {
					'variable': {
						pattern: /^(!?\[)[^\]]+/,
						lookbehind: true
					},
					'string': /(?:"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|\((?:\\.|[^)\\])*\))$/,
					'punctuation': /^[\[\]!:]|[<>]/
				},
				alias: 'url'
			},
			'bold': {
				// **strong**
				// __strong__

				// allow one nested instance of italic text using the same delimiter
				pattern: createInline(/\b__(?:(?!_)<inner>|_(?:(?!_)<inner>)+_)+__\b|\*\*(?:(?!\*)<inner>|\*(?:(?!\*)<inner>)+\*)+\*\*/.source),
				lookbehind: true,
				greedy: true,
				inside: {
					'content': {
						pattern: /(^..)[\s\S]+(?=..$)/,
						lookbehind: true,
						inside: {} // see below
					},
					'punctuation': /\*\*|__/
				}
			},
			'italic': {
				// *em*
				// _em_

				// allow one nested instance of bold text using the same delimiter
				pattern: createInline(/\b_(?:(?!_)<inner>|__(?:(?!_)<inner>)+__)+_\b|\*(?:(?!\*)<inner>|\*\*(?:(?!\*)<inner>)+\*\*)+\*/.source),
				lookbehind: true,
				greedy: true,
				inside: {
					'content': {
						pattern: /(^.)[\s\S]+(?=.$)/,
						lookbehind: true,
						inside: {} // see below
					},
					'punctuation': /[*_]/
				}
			},
			'strike': {
				// ~~strike through~~
				// ~strike~
				// eslint-disable-next-line regexp/strict
				pattern: createInline(/(~~?)(?:(?!~)<inner>)+\2/.source),
				lookbehind: true,
				greedy: true,
				inside: {
					'content': {
						pattern: /(^~~?)[\s\S]+(?=\1$)/,
						lookbehind: true,
						inside: {} // see below
					},
					'punctuation': /~~?/
				}
			},
			'code-snippet': {
				// `code`
				// ``code``
				pattern: /(^|[^\\`])(?:``[^`\r\n]+(?:`[^`\r\n]+)*``(?!`)|`[^`\r\n]+`(?!`))/,
				lookbehind: true,
				greedy: true,
				alias: ['code', 'keyword']
			},
			'url': {
				// [example](http://example.com "Optional title")
				// [example][id]
				// [example] [id]
				pattern: createInline(/!?\[(?:(?!\])<inner>)+\](?:\([^\s)]+(?:[\t ]+"(?:\\.|[^"\\])*")?\)|[ \t]?\[(?:(?!\])<inner>)+\])/.source),
				lookbehind: true,
				greedy: true,
				inside: {
					'operator': /^!/,
					'content': {
						pattern: /(^\[)[^\]]+(?=\])/,
						lookbehind: true,
						inside: {} // see below
					},
					'variable': {
						pattern: /(^\][ \t]?\[)[^\]]+(?=\]$)/,
						lookbehind: true
					},
					'url': {
						pattern: /(^\]\()[^\s)]+/,
						lookbehind: true
					},
					'string': {
						pattern: /(^[ \t]+)"(?:\\.|[^"\\])*"(?=\)$)/,
						lookbehind: true
					}
				}
			}
		});

		['url', 'bold', 'italic', 'strike'].forEach(function (token) {
			['url', 'bold', 'italic', 'strike', 'code-snippet'].forEach(function (inside) {
				if (token !== inside) {
					Prism.languages.markdown[token].inside.content.inside[inside] = Prism.languages.markdown[inside];
				}
			});
		});

		Prism.hooks.add('after-tokenize', function (env) {
			if (env.language !== 'markdown' && env.language !== 'md') {
				return;
			}

			function walkTokens(tokens) {
				if (!tokens || typeof tokens === 'string') {
					return;
				}

				for (var i = 0, l = tokens.length; i < l; i++) {
					var token = tokens[i];

					if (token.type !== 'code') {
						walkTokens(token.content);
						continue;
					}

					/*
					 * Add the correct `language-xxxx` class to this code block. Keep in mind that the `code-language` token
					 * is optional. But the grammar is defined so that there is only one case we have to handle:
					 *
					 * token.content = [
					 *     <span class="punctuation">```</span>,
					 *     <span class="code-language">xxxx</span>,
					 *     '\n', // exactly one new lines (\r or \n or \r\n)
					 *     <span class="code-block">...</span>,
					 *     '\n', // exactly one new lines again
					 *     <span class="punctuation">```</span>
					 * ];
					 */

					var codeLang = token.content[1];
					var codeBlock = token.content[3];

					if (codeLang && codeBlock &&
						codeLang.type === 'code-language' && codeBlock.type === 'code-block' &&
						typeof codeLang.content === 'string') {

						// this might be a language that Prism does not support

						// do some replacements to support C++, C#, and F#
						var lang = codeLang.content.replace(/\b#/g, 'sharp').replace(/\b\+\+/g, 'pp');
						// only use the first word
						lang = (/[a-z][\w-]*/i.exec(lang) || [''])[0].toLowerCase();
						var alias = 'language-' + lang;

						// add alias
						if (!codeBlock.alias) {
							codeBlock.alias = [alias];
						} else if (typeof codeBlock.alias === 'string') {
							codeBlock.alias = [codeBlock.alias, alias];
						} else {
							codeBlock.alias.push(alias);
						}
					}
				}
			}

			walkTokens(env.tokens);
		});

		Prism.hooks.add('wrap', function (env) {
			if (env.type !== 'code-block') {
				return;
			}

			var codeLang = '';
			for (var i = 0, l = env.classes.length; i < l; i++) {
				var cls = env.classes[i];
				var match = /language-(.+)/.exec(cls);
				if (match) {
					codeLang = match[1];
					break;
				}
			}

			var grammar = Prism.languages[codeLang];

			if (!grammar) {
				if (codeLang && codeLang !== 'none' && Prism.plugins.autoloader) {
					var id = 'md-' + new Date().valueOf() + '-' + Math.floor(Math.random() * 1e16);
					env.attributes['id'] = id;

					Prism.plugins.autoloader.loadLanguages(codeLang, function () {
						var ele = document.getElementById(id);
						if (ele) {
							ele.innerHTML = Prism.highlight(ele.textContent, Prism.languages[codeLang], codeLang);
						}
					});
				}
			} else {
				env.content = Prism.highlight(textContent(env.content), grammar, codeLang);
			}
		});

		var tagPattern = RegExp(Prism.languages.markup.tag.pattern.source, 'gi');

		/**
		 * A list of known entity names.
		 *
		 * This will always be incomplete to save space. The current list is the one used by lowdash's unescape function.
		 *
		 * @see {@link https://github.com/lodash/lodash/blob/2da024c3b4f9947a48517639de7560457cd4ec6c/unescape.js#L2}
		 */
		var KNOWN_ENTITY_NAMES = {
			'amp': '&',
			'lt': '<',
			'gt': '>',
			'quot': '"',
		};

		// IE 11 doesn't support `String.fromCodePoint`
		var fromCodePoint = String.fromCodePoint || String.fromCharCode;

		/**
		 * Returns the text content of a given HTML source code string.
		 *
		 * @param {string} html
		 * @returns {string}
		 */
		function textContent(html) {
			// remove all tags
			var text = html.replace(tagPattern, '');

			// decode known entities
			text = text.replace(/&(\w{1,8}|#x?[\da-f]{1,8});/gi, function (m, code) {
				code = code.toLowerCase();

				if (code[0] === '#') {
					var value;
					if (code[1] === 'x') {
						value = parseInt(code.slice(2), 16);
					} else {
						value = Number(code.slice(1));
					}

					return fromCodePoint(value);
				} else {
					var known = KNOWN_ENTITY_NAMES[code];
					if (known) {
						return known;
					}

					// unable to decode
					return m;
				}
			});

			return text;
		}

		Prism.languages.md = Prism.languages.markdown;

	}(Prism));

	Prism.languages.c = Prism.languages.extend('clike', {
		'comment': {
			pattern: /\/\/(?:[^\r\n\\]|\\(?:\r\n?|\n|(?![\r\n])))*|\/\*[\s\S]*?(?:\*\/|$)/,
			greedy: true
		},
		'string': {
			// https://en.cppreference.com/w/c/language/string_literal
			pattern: /"(?:\\(?:\r\n|[\s\S])|[^"\\\r\n])*"/,
			greedy: true
		},
		'class-name': {
			pattern: /(\b(?:enum|struct)\s+(?:__attribute__\s*\(\([\s\S]*?\)\)\s*)?)\w+|\b[a-z]\w*_t\b/,
			lookbehind: true
		},
		'keyword': /\b(?:_Alignas|_Alignof|_Atomic|_Bool|_Complex|_Generic|_Imaginary|_Noreturn|_Static_assert|_Thread_local|__attribute__|asm|auto|break|case|char|const|continue|default|do|double|else|enum|extern|float|for|goto|if|inline|int|long|register|return|short|signed|sizeof|static|struct|switch|typedef|typeof|union|unsigned|void|volatile|while)\b/,
		'function': /\b[a-z_]\w*(?=\s*\()/i,
		'number': /(?:\b0x(?:[\da-f]+(?:\.[\da-f]*)?|\.[\da-f]+)(?:p[+-]?\d+)?|(?:\b\d+(?:\.\d*)?|\B\.\d+)(?:e[+-]?\d+)?)[ful]{0,4}/i,
		'operator': />>=?|<<=?|->|([-+&|:])\1|[?:~]|[-+*/%&|^!=<>]=?/
	});

	Prism.languages.insertBefore('c', 'string', {
		'char': {
			// https://en.cppreference.com/w/c/language/character_constant
			pattern: /'(?:\\(?:\r\n|[\s\S])|[^'\\\r\n]){0,32}'/,
			greedy: true
		}
	});

	Prism.languages.insertBefore('c', 'string', {
		'macro': {
			// allow for multiline macro definitions
			// spaces after the # character compile fine with gcc
			pattern: /(^[\t ]*)#\s*[a-z](?:[^\r\n\\/]|\/(?!\*)|\/\*(?:[^*]|\*(?!\/))*\*\/|\\(?:\r\n|[\s\S]))*/im,
			lookbehind: true,
			greedy: true,
			alias: 'property',
			inside: {
				'string': [
					{
						// highlight the path of the include statement as a string
						pattern: /^(#\s*include\s*)<[^>]+>/,
						lookbehind: true
					},
					Prism.languages.c['string']
				],
				'char': Prism.languages.c['char'],
				'comment': Prism.languages.c['comment'],
				'macro-name': [
					{
						pattern: /(^#\s*define\s+)\w+\b(?!\()/i,
						lookbehind: true
					},
					{
						pattern: /(^#\s*define\s+)\w+\b(?=\()/i,
						lookbehind: true,
						alias: 'function'
					}
				],
				// highlight macro directives as keywords
				'directive': {
					pattern: /^(#\s*)[a-z]+/,
					lookbehind: true,
					alias: 'keyword'
				},
				'directive-hash': /^#/,
				'punctuation': /##|\\(?=[\r\n])/,
				'expression': {
					pattern: /\S[\s\S]*/,
					inside: Prism.languages.c
				}
			}
		}
	});

	Prism.languages.insertBefore('c', 'function', {
		// highlight predefined macros as constants
		'constant': /\b(?:EOF|NULL|SEEK_CUR|SEEK_END|SEEK_SET|__DATE__|__FILE__|__LINE__|__TIMESTAMP__|__TIME__|__func__|stderr|stdin|stdout)\b/
	});

	delete Prism.languages.c['boolean'];

	(function (Prism) {

		var string = /(?:"(?:\\(?:\r\n|[\s\S])|[^"\\\r\n])*"|'(?:\\(?:\r\n|[\s\S])|[^'\\\r\n])*')/;

		Prism.languages.css = {
			'comment': /\/\*[\s\S]*?\*\//,
			'atrule': {
				pattern: RegExp('@[\\w-](?:' + /[^;{\s"']|\s+(?!\s)/.source + '|' + string.source + ')*?' + /(?:;|(?=\s*\{))/.source),
				inside: {
					'rule': /^@[\w-]+/,
					'selector-function-argument': {
						pattern: /(\bselector\s*\(\s*(?![\s)]))(?:[^()\s]|\s+(?![\s)])|\((?:[^()]|\([^()]*\))*\))+(?=\s*\))/,
						lookbehind: true,
						alias: 'selector'
					},
					'keyword': {
						pattern: /(^|[^\w-])(?:and|not|only|or)(?![\w-])/,
						lookbehind: true
					}
					// See rest below
				}
			},
			'url': {
				// https://drafts.csswg.org/css-values-3/#urls
				pattern: RegExp('\\burl\\((?:' + string.source + '|' + /(?:[^\\\r\n()"']|\\[\s\S])*/.source + ')\\)', 'i'),
				greedy: true,
				inside: {
					'function': /^url/i,
					'punctuation': /^\(|\)$/,
					'string': {
						pattern: RegExp('^' + string.source + '$'),
						alias: 'url'
					}
				}
			},
			'selector': {
				pattern: RegExp('(^|[{}\\s])[^{}\\s](?:[^{};"\'\\s]|\\s+(?![\\s{])|' + string.source + ')*(?=\\s*\\{)'),
				lookbehind: true
			},
			'string': {
				pattern: string,
				greedy: true
			},
			'property': {
				pattern: /(^|[^-\w\xA0-\uFFFF])(?!\s)[-_a-z\xA0-\uFFFF](?:(?!\s)[-\w\xA0-\uFFFF])*(?=\s*:)/i,
				lookbehind: true
			},
			'important': /!important\b/i,
			'function': {
				pattern: /(^|[^-a-z0-9])[-a-z0-9]+(?=\()/i,
				lookbehind: true
			},
			'punctuation': /[(){};:,]/
		};

		Prism.languages.css['atrule'].inside.rest = Prism.languages.css;

		var markup = Prism.languages.markup;
		if (markup) {
			markup.tag.addInlined('style', 'css');
			markup.tag.addAttribute('style', 'css');
		}

	}(Prism));

	Prism.languages.objectivec = Prism.languages.extend('c', {
		'string': {
			pattern: /@?"(?:\\(?:\r\n|[\s\S])|[^"\\\r\n])*"/,
			greedy: true
		},
		'keyword': /\b(?:asm|auto|break|case|char|const|continue|default|do|double|else|enum|extern|float|for|goto|if|in|inline|int|long|register|return|self|short|signed|sizeof|static|struct|super|switch|typedef|typeof|union|unsigned|void|volatile|while)\b|(?:@interface|@end|@implementation|@protocol|@class|@public|@protected|@private|@property|@try|@catch|@finally|@throw|@synthesize|@dynamic|@selector)\b/,
		'operator': /-[->]?|\+\+?|!=?|<<?=?|>>?=?|==?|&&?|\|\|?|[~^%?*\/@]/
	});

	delete Prism.languages.objectivec['class-name'];

	Prism.languages.objc = Prism.languages.objectivec;

	Prism.languages.sql = {
		'comment': {
			pattern: /(^|[^\\])(?:\/\*[\s\S]*?\*\/|(?:--|\/\/|#).*)/,
			lookbehind: true
		},
		'variable': [
			{
				pattern: /@(["'`])(?:\\[\s\S]|(?!\1)[^\\])+\1/,
				greedy: true
			},
			/@[\w.$]+/
		],
		'string': {
			pattern: /(^|[^@\\])("|')(?:\\[\s\S]|(?!\2)[^\\]|\2\2)*\2/,
			greedy: true,
			lookbehind: true
		},
		'identifier': {
			pattern: /(^|[^@\\])`(?:\\[\s\S]|[^`\\]|``)*`/,
			greedy: true,
			lookbehind: true,
			inside: {
				'punctuation': /^`|`$/
			}
		},
		'function': /\b(?:AVG|COUNT|FIRST|FORMAT|LAST|LCASE|LEN|MAX|MID|MIN|MOD|NOW|ROUND|SUM|UCASE)(?=\s*\()/i, // Should we highlight user defined functions too?
		'keyword': /\b(?:ACTION|ADD|AFTER|ALGORITHM|ALL|ALTER|ANALYZE|ANY|APPLY|AS|ASC|AUTHORIZATION|AUTO_INCREMENT|BACKUP|BDB|BEGIN|BERKELEYDB|BIGINT|BINARY|BIT|BLOB|BOOL|BOOLEAN|BREAK|BROWSE|BTREE|BULK|BY|CALL|CASCADED?|CASE|CHAIN|CHAR(?:ACTER|SET)?|CHECK(?:POINT)?|CLOSE|CLUSTERED|COALESCE|COLLATE|COLUMNS?|COMMENT|COMMIT(?:TED)?|COMPUTE|CONNECT|CONSISTENT|CONSTRAINT|CONTAINS(?:TABLE)?|CONTINUE|CONVERT|CREATE|CROSS|CURRENT(?:_DATE|_TIME|_TIMESTAMP|_USER)?|CURSOR|CYCLE|DATA(?:BASES?)?|DATE(?:TIME)?|DAY|DBCC|DEALLOCATE|DEC|DECIMAL|DECLARE|DEFAULT|DEFINER|DELAYED|DELETE|DELIMITERS?|DENY|DESC|DESCRIBE|DETERMINISTIC|DISABLE|DISCARD|DISK|DISTINCT|DISTINCTROW|DISTRIBUTED|DO|DOUBLE|DROP|DUMMY|DUMP(?:FILE)?|DUPLICATE|ELSE(?:IF)?|ENABLE|ENCLOSED|END|ENGINE|ENUM|ERRLVL|ERRORS|ESCAPED?|EXCEPT|EXEC(?:UTE)?|EXISTS|EXIT|EXPLAIN|EXTENDED|FETCH|FIELDS|FILE|FILLFACTOR|FIRST|FIXED|FLOAT|FOLLOWING|FOR(?: EACH ROW)?|FORCE|FOREIGN|FREETEXT(?:TABLE)?|FROM|FULL|FUNCTION|GEOMETRY(?:COLLECTION)?|GLOBAL|GOTO|GRANT|GROUP|HANDLER|HASH|HAVING|HOLDLOCK|HOUR|IDENTITY(?:COL|_INSERT)?|IF|IGNORE|IMPORT|INDEX|INFILE|INNER|INNODB|INOUT|INSERT|INT|INTEGER|INTERSECT|INTERVAL|INTO|INVOKER|ISOLATION|ITERATE|JOIN|KEYS?|KILL|LANGUAGE|LAST|LEAVE|LEFT|LEVEL|LIMIT|LINENO|LINES|LINESTRING|LOAD|LOCAL|LOCK|LONG(?:BLOB|TEXT)|LOOP|MATCH(?:ED)?|MEDIUM(?:BLOB|INT|TEXT)|MERGE|MIDDLEINT|MINUTE|MODE|MODIFIES|MODIFY|MONTH|MULTI(?:LINESTRING|POINT|POLYGON)|NATIONAL|NATURAL|NCHAR|NEXT|NO|NONCLUSTERED|NULLIF|NUMERIC|OFF?|OFFSETS?|ON|OPEN(?:DATASOURCE|QUERY|ROWSET)?|OPTIMIZE|OPTION(?:ALLY)?|ORDER|OUT(?:ER|FILE)?|OVER|PARTIAL|PARTITION|PERCENT|PIVOT|PLAN|POINT|POLYGON|PRECEDING|PRECISION|PREPARE|PREV|PRIMARY|PRINT|PRIVILEGES|PROC(?:EDURE)?|PUBLIC|PURGE|QUICK|RAISERROR|READS?|REAL|RECONFIGURE|REFERENCES|RELEASE|RENAME|REPEAT(?:ABLE)?|REPLACE|REPLICATION|REQUIRE|RESIGNAL|RESTORE|RESTRICT|RETURN(?:ING|S)?|REVOKE|RIGHT|ROLLBACK|ROUTINE|ROW(?:COUNT|GUIDCOL|S)?|RTREE|RULE|SAVE(?:POINT)?|SCHEMA|SECOND|SELECT|SERIAL(?:IZABLE)?|SESSION(?:_USER)?|SET(?:USER)?|SHARE|SHOW|SHUTDOWN|SIMPLE|SMALLINT|SNAPSHOT|SOME|SONAME|SQL|START(?:ING)?|STATISTICS|STATUS|STRIPED|SYSTEM_USER|TABLES?|TABLESPACE|TEMP(?:ORARY|TABLE)?|TERMINATED|TEXT(?:SIZE)?|THEN|TIME(?:STAMP)?|TINY(?:BLOB|INT|TEXT)|TOP?|TRAN(?:SACTIONS?)?|TRIGGER|TRUNCATE|TSEQUAL|TYPES?|UNBOUNDED|UNCOMMITTED|UNDEFINED|UNION|UNIQUE|UNLOCK|UNPIVOT|UNSIGNED|UPDATE(?:TEXT)?|USAGE|USE|USER|USING|VALUES?|VAR(?:BINARY|CHAR|CHARACTER|YING)|VIEW|WAITFOR|WARNINGS|WHEN|WHERE|WHILE|WITH(?: ROLLUP|IN)?|WORK|WRITE(?:TEXT)?|YEAR)\b/i,
		'boolean': /\b(?:FALSE|NULL|TRUE)\b/i,
		'number': /\b0x[\da-f]+\b|\b\d+(?:\.\d*)?|\B\.\d+\b/i,
		'operator': /[-+*\/=%^~]|&&?|\|\|?|!=?|<(?:=>?|<|>)?|>[>=]?|\b(?:AND|BETWEEN|DIV|ILIKE|IN|IS|LIKE|NOT|OR|REGEXP|RLIKE|SOUNDS LIKE|XOR)\b/i,
		'punctuation': /[;[\]()`,.]/
	};

	(function (Prism) {

		var powershell = Prism.languages.powershell = {
			'comment': [
				{
					pattern: /(^|[^`])<#[\s\S]*?#>/,
					lookbehind: true
				},
				{
					pattern: /(^|[^`])#.*/,
					lookbehind: true
				}
			],
			'string': [
				{
					pattern: /"(?:`[\s\S]|[^`"])*"/,
					greedy: true,
					inside: null // see below
				},
				{
					pattern: /'(?:[^']|'')*'/,
					greedy: true
				}
			],
			// Matches name spaces as well as casts, attribute decorators. Force starting with letter to avoid matching array indices
			// Supports two levels of nested brackets (e.g. `[OutputType([System.Collections.Generic.List[int]])]`)
			'namespace': /\[[a-z](?:\[(?:\[[^\]]*\]|[^\[\]])*\]|[^\[\]])*\]/i,
			'boolean': /\$(?:false|true)\b/i,
			'variable': /\$\w+\b/,
			// Cmdlets and aliases. Aliases should come last, otherwise "write" gets preferred over "write-host" for example
			// Get-Command | ?{ $_.ModuleName -match "Microsoft.PowerShell.(Util|Core|Management)" }
			// Get-Alias | ?{ $_.ReferencedCommand.Module.Name -match "Microsoft.PowerShell.(Util|Core|Management)" }
			'function': [
				/\b(?:Add|Approve|Assert|Backup|Block|Checkpoint|Clear|Close|Compare|Complete|Compress|Confirm|Connect|Convert|ConvertFrom|ConvertTo|Copy|Debug|Deny|Disable|Disconnect|Dismount|Edit|Enable|Enter|Exit|Expand|Export|Find|ForEach|Format|Get|Grant|Group|Hide|Import|Initialize|Install|Invoke|Join|Limit|Lock|Measure|Merge|Move|New|Open|Optimize|Out|Ping|Pop|Protect|Publish|Push|Read|Receive|Redo|Register|Remove|Rename|Repair|Request|Reset|Resize|Resolve|Restart|Restore|Resume|Revoke|Save|Search|Select|Send|Set|Show|Skip|Sort|Split|Start|Step|Stop|Submit|Suspend|Switch|Sync|Tee|Test|Trace|Unblock|Undo|Uninstall|Unlock|Unprotect|Unpublish|Unregister|Update|Use|Wait|Watch|Where|Write)-[a-z]+\b/i,
				/\b(?:ac|cat|chdir|clc|cli|clp|clv|compare|copy|cp|cpi|cpp|cvpa|dbp|del|diff|dir|ebp|echo|epal|epcsv|epsn|erase|fc|fl|ft|fw|gal|gbp|gc|gci|gcs|gdr|gi|gl|gm|gp|gps|group|gsv|gu|gv|gwmi|iex|ii|ipal|ipcsv|ipsn|irm|iwmi|iwr|kill|lp|ls|measure|mi|mount|move|mp|mv|nal|ndr|ni|nv|ogv|popd|ps|pushd|pwd|rbp|rd|rdr|ren|ri|rm|rmdir|rni|rnp|rp|rv|rvpa|rwmi|sal|saps|sasv|sbp|sc|select|set|shcm|si|sl|sleep|sls|sort|sp|spps|spsv|start|sv|swmi|tee|trcm|type|write)\b/i
			],
			// per http://technet.microsoft.com/en-us/library/hh847744.aspx
			'keyword': /\b(?:Begin|Break|Catch|Class|Continue|Data|Define|Do|DynamicParam|Else|ElseIf|End|Exit|Filter|Finally|For|ForEach|From|Function|If|InlineScript|Parallel|Param|Process|Return|Sequence|Switch|Throw|Trap|Try|Until|Using|Var|While|Workflow)\b/i,
			'operator': {
				pattern: /(^|\W)(?:!|-(?:b?(?:and|x?or)|as|(?:Not)?(?:Contains|In|Like|Match)|eq|ge|gt|is(?:Not)?|Join|le|lt|ne|not|Replace|sh[lr])\b|-[-=]?|\+[+=]?|[*\/%]=?)/i,
				lookbehind: true
			},
			'punctuation': /[|{}[\];(),.]/
		};

		// Variable interpolation inside strings, and nested expressions
		powershell.string[0].inside = {
			'function': {
				// Allow for one level of nesting
				pattern: /(^|[^`])\$\((?:\$\([^\r\n()]*\)|(?!\$\()[^\r\n)])*\)/,
				lookbehind: true,
				inside: powershell
			},
			'boolean': powershell.boolean,
			'variable': powershell.variable,
		};

	}(Prism));

	var prismPython = {};

	var hasRequiredPrismPython;

	function requirePrismPython () {
		if (hasRequiredPrismPython) return prismPython;
		hasRequiredPrismPython = 1;
		Prism.languages.python = {
			'comment': {
				pattern: /(^|[^\\])#.*/,
				lookbehind: true,
				greedy: true
			},
			'string-interpolation': {
				pattern: /(?:f|fr|rf)(?:("""|''')[\s\S]*?\1|("|')(?:\\.|(?!\2)[^\\\r\n])*\2)/i,
				greedy: true,
				inside: {
					'interpolation': {
						// "{" <expression> <optional "!s", "!r", or "!a"> <optional ":" format specifier> "}"
						pattern: /((?:^|[^{])(?:\{\{)*)\{(?!\{)(?:[^{}]|\{(?!\{)(?:[^{}]|\{(?!\{)(?:[^{}])+\})+\})+\}/,
						lookbehind: true,
						inside: {
							'format-spec': {
								pattern: /(:)[^:(){}]+(?=\}$)/,
								lookbehind: true
							},
							'conversion-option': {
								pattern: /![sra](?=[:}]$)/,
								alias: 'punctuation'
							},
							rest: null
						}
					},
					'string': /[\s\S]+/
				}
			},
			'triple-quoted-string': {
				pattern: /(?:[rub]|br|rb)?("""|''')[\s\S]*?\1/i,
				greedy: true,
				alias: 'string'
			},
			'string': {
				pattern: /(?:[rub]|br|rb)?("|')(?:\\.|(?!\1)[^\\\r\n])*\1/i,
				greedy: true
			},
			'function': {
				pattern: /((?:^|\s)def[ \t]+)[a-zA-Z_]\w*(?=\s*\()/g,
				lookbehind: true
			},
			'class-name': {
				pattern: /(\bclass\s+)\w+/i,
				lookbehind: true
			},
			'decorator': {
				pattern: /(^[\t ]*)@\w+(?:\.\w+)*/m,
				lookbehind: true,
				alias: ['annotation', 'punctuation'],
				inside: {
					'punctuation': /\./
				}
			},
			'keyword': /\b(?:_(?=\s*:)|and|as|assert|async|await|break|case|class|continue|def|del|elif|else|except|exec|finally|for|from|global|if|import|in|is|lambda|match|nonlocal|not|or|pass|print|raise|return|try|while|with|yield)\b/,
			'builtin': /\b(?:__import__|abs|all|any|apply|ascii|basestring|bin|bool|buffer|bytearray|bytes|callable|chr|classmethod|cmp|coerce|compile|complex|delattr|dict|dir|divmod|enumerate|eval|execfile|file|filter|float|format|frozenset|getattr|globals|hasattr|hash|help|hex|id|input|int|intern|isinstance|issubclass|iter|len|list|locals|long|map|max|memoryview|min|next|object|oct|open|ord|pow|property|range|raw_input|reduce|reload|repr|reversed|round|set|setattr|slice|sorted|staticmethod|str|sum|super|tuple|type|unichr|unicode|vars|xrange|zip)\b/,
			'boolean': /\b(?:False|None|True)\b/,
			'number': /\b0(?:b(?:_?[01])+|o(?:_?[0-7])+|x(?:_?[a-f0-9])+)\b|(?:\b\d+(?:_\d+)*(?:\.(?:\d+(?:_\d+)*)?)?|\B\.\d+(?:_\d+)*)(?:e[+-]?\d+(?:_\d+)*)?j?(?!\w)/i,
			'operator': /[-+%=]=?|!=|:=|\*\*?=?|\/\/?=?|<[<=>]?|>[=>]?|[&|^~]/,
			'punctuation': /[{}[\];(),.:]/
		};

		Prism.languages.python['string-interpolation'].inside['interpolation'].inside.rest = Prism.languages.python;

		Prism.languages.py = Prism.languages.python;
		return prismPython;
	}

	requirePrismPython();

	var prismRust = {};

	var hasRequiredPrismRust;

	function requirePrismRust () {
		if (hasRequiredPrismRust) return prismRust;
		hasRequiredPrismRust = 1;
		(function (Prism) {

			var multilineComment = /\/\*(?:[^*/]|\*(?!\/)|\/(?!\*)|<self>)*\*\//.source;
			for (var i = 0; i < 2; i++) {
				// support 4 levels of nested comments
				multilineComment = multilineComment.replace(/<self>/g, function () { return multilineComment; });
			}
			multilineComment = multilineComment.replace(/<self>/g, function () { return /[^\s\S]/.source; });


			Prism.languages.rust = {
				'comment': [
					{
						pattern: RegExp(/(^|[^\\])/.source + multilineComment),
						lookbehind: true,
						greedy: true
					},
					{
						pattern: /(^|[^\\:])\/\/.*/,
						lookbehind: true,
						greedy: true
					}
				],
				'string': {
					pattern: /b?"(?:\\[\s\S]|[^\\"])*"|b?r(#*)"(?:[^"]|"(?!\1))*"\1/,
					greedy: true
				},
				'char': {
					pattern: /b?'(?:\\(?:x[0-7][\da-fA-F]|u\{(?:[\da-fA-F]_*){1,6}\}|.)|[^\\\r\n\t'])'/,
					greedy: true
				},
				'attribute': {
					pattern: /#!?\[(?:[^\[\]"]|"(?:\\[\s\S]|[^\\"])*")*\]/,
					greedy: true,
					alias: 'attr-name',
					inside: {
						'string': null // see below
					}
				},

				// Closure params should not be confused with bitwise OR |
				'closure-params': {
					pattern: /([=(,:]\s*|\bmove\s*)\|[^|]*\||\|[^|]*\|(?=\s*(?:\{|->))/,
					lookbehind: true,
					greedy: true,
					inside: {
						'closure-punctuation': {
							pattern: /^\||\|$/,
							alias: 'punctuation'
						},
						rest: null // see below
					}
				},

				'lifetime-annotation': {
					pattern: /'\w+/,
					alias: 'symbol'
				},

				'fragment-specifier': {
					pattern: /(\$\w+:)[a-z]+/,
					lookbehind: true,
					alias: 'punctuation'
				},
				'variable': /\$\w+/,

				'function-definition': {
					pattern: /(\bfn\s+)\w+/,
					lookbehind: true,
					alias: 'function'
				},
				'type-definition': {
					pattern: /(\b(?:enum|struct|trait|type|union)\s+)\w+/,
					lookbehind: true,
					alias: 'class-name'
				},
				'module-declaration': [
					{
						pattern: /(\b(?:crate|mod)\s+)[a-z][a-z_\d]*/,
						lookbehind: true,
						alias: 'namespace'
					},
					{
						pattern: /(\b(?:crate|self|super)\s*)::\s*[a-z][a-z_\d]*\b(?:\s*::(?:\s*[a-z][a-z_\d]*\s*::)*)?/,
						lookbehind: true,
						alias: 'namespace',
						inside: {
							'punctuation': /::/
						}
					}
				],
				'keyword': [
					// https://github.com/rust-lang/reference/blob/master/src/keywords.md
					/\b(?:Self|abstract|as|async|await|become|box|break|const|continue|crate|do|dyn|else|enum|extern|final|fn|for|if|impl|in|let|loop|macro|match|mod|move|mut|override|priv|pub|ref|return|self|static|struct|super|trait|try|type|typeof|union|unsafe|unsized|use|virtual|where|while|yield)\b/,
					// primitives and str
					// https://doc.rust-lang.org/stable/rust-by-example/primitives.html
					/\b(?:bool|char|f(?:32|64)|[ui](?:8|16|32|64|128|size)|str)\b/
				],

				// functions can technically start with an upper-case letter, but this will introduce a lot of false positives
				// and Rust's naming conventions recommend snake_case anyway.
				// https://doc.rust-lang.org/1.0.0/style/style/naming/README.html
				'function': /\b[a-z_]\w*(?=\s*(?:::\s*<|\())/,
				'macro': {
					pattern: /\b\w+!/,
					alias: 'property'
				},
				'constant': /\b[A-Z_][A-Z_\d]+\b/,
				'class-name': /\b[A-Z]\w*\b/,

				'namespace': {
					pattern: /(?:\b[a-z][a-z_\d]*\s*::\s*)*\b[a-z][a-z_\d]*\s*::(?!\s*<)/,
					inside: {
						'punctuation': /::/
					}
				},

				// Hex, oct, bin, dec numbers with visual separators and type suffix
				'number': /\b(?:0x[\dA-Fa-f](?:_?[\dA-Fa-f])*|0o[0-7](?:_?[0-7])*|0b[01](?:_?[01])*|(?:(?:\d(?:_?\d)*)?\.)?\d(?:_?\d)*(?:[Ee][+-]?\d+)?)(?:_?(?:f32|f64|[iu](?:8|16|32|64|size)?))?\b/,
				'boolean': /\b(?:false|true)\b/,
				'punctuation': /->|\.\.=|\.{1,3}|::|[{}[\];(),:]/,
				'operator': /[-+*\/%!^]=?|=[=>]?|&[&=]?|\|[|=]?|<<?=?|>>?=?|[@?]/
			};

			Prism.languages.rust['closure-params'].inside.rest = Prism.languages.rust;
			Prism.languages.rust['attribute'].inside['string'] = Prism.languages.rust['string'];

		}(Prism));
		return prismRust;
	}

	requirePrismRust();

	Prism.languages.swift = {
		'comment': {
			// Nested comments are supported up to 2 levels
			pattern: /(^|[^\\:])(?:\/\/.*|\/\*(?:[^/*]|\/(?!\*)|\*(?!\/)|\/\*(?:[^*]|\*(?!\/))*\*\/)*\*\/)/,
			lookbehind: true,
			greedy: true
		},
		'string-literal': [
			// https://docs.swift.org/swift-book/LanguageGuide/StringsAndCharacters.html
			{
				pattern: RegExp(
					/(^|[^"#])/.source
					+ '(?:'
					// single-line string
					+ /"(?:\\(?:\((?:[^()]|\([^()]*\))*\)|\r\n|[^(])|[^\\\r\n"])*"/.source
					+ '|'
					// multi-line string
					+ /"""(?:\\(?:\((?:[^()]|\([^()]*\))*\)|[^(])|[^\\"]|"(?!""))*"""/.source
					+ ')'
					+ /(?!["#])/.source
				),
				lookbehind: true,
				greedy: true,
				inside: {
					'interpolation': {
						pattern: /(\\\()(?:[^()]|\([^()]*\))*(?=\))/,
						lookbehind: true,
						inside: null // see below
					},
					'interpolation-punctuation': {
						pattern: /^\)|\\\($/,
						alias: 'punctuation'
					},
					'punctuation': /\\(?=[\r\n])/,
					'string': /[\s\S]+/
				}
			},
			{
				pattern: RegExp(
					/(^|[^"#])(#+)/.source
					+ '(?:'
					// single-line string
					+ /"(?:\\(?:#+\((?:[^()]|\([^()]*\))*\)|\r\n|[^#])|[^\\\r\n])*?"/.source
					+ '|'
					// multi-line string
					+ /"""(?:\\(?:#+\((?:[^()]|\([^()]*\))*\)|[^#])|[^\\])*?"""/.source
					+ ')'
					+ '\\2'
				),
				lookbehind: true,
				greedy: true,
				inside: {
					'interpolation': {
						pattern: /(\\#+\()(?:[^()]|\([^()]*\))*(?=\))/,
						lookbehind: true,
						inside: null // see below
					},
					'interpolation-punctuation': {
						pattern: /^\)|\\#+\($/,
						alias: 'punctuation'
					},
					'string': /[\s\S]+/
				}
			},
		],

		'directive': {
			// directives with conditions
			pattern: RegExp(
				/#/.source
				+ '(?:'
				+ (
					/(?:elseif|if)\b/.source
					+ '(?:[ \t]*'
					// This regex is a little complex. It's equivalent to this:
					//   (?:![ \t]*)?(?:\b\w+\b(?:[ \t]*<round>)?|<round>)(?:[ \t]*(?:&&|\|\|))?
					// where <round> is a general parentheses expression.
					+ /(?:![ \t]*)?(?:\b\w+\b(?:[ \t]*\((?:[^()]|\([^()]*\))*\))?|\((?:[^()]|\([^()]*\))*\))(?:[ \t]*(?:&&|\|\|))?/.source
					+ ')+'
				)
				+ '|'
				+ /(?:else|endif)\b/.source
				+ ')'
			),
			alias: 'property',
			inside: {
				'directive-name': /^#\w+/,
				'boolean': /\b(?:false|true)\b/,
				'number': /\b\d+(?:\.\d+)*\b/,
				'operator': /!|&&|\|\||[<>]=?/,
				'punctuation': /[(),]/
			}
		},
		'literal': {
			pattern: /#(?:colorLiteral|column|dsohandle|file(?:ID|Literal|Path)?|function|imageLiteral|line)\b/,
			alias: 'constant'
		},
		'other-directive': {
			pattern: /#\w+\b/,
			alias: 'property'
		},

		'attribute': {
			pattern: /@\w+/,
			alias: 'atrule'
		},

		'function-definition': {
			pattern: /(\bfunc\s+)\w+/,
			lookbehind: true,
			alias: 'function'
		},
		'label': {
			// https://docs.swift.org/swift-book/LanguageGuide/ControlFlow.html#ID141
			pattern: /\b(break|continue)\s+\w+|\b[a-zA-Z_]\w*(?=\s*:\s*(?:for|repeat|while)\b)/,
			lookbehind: true,
			alias: 'important'
		},

		'keyword': /\b(?:Any|Protocol|Self|Type|actor|as|assignment|associatedtype|associativity|async|await|break|case|catch|class|continue|convenience|default|defer|deinit|didSet|do|dynamic|else|enum|extension|fallthrough|fileprivate|final|for|func|get|guard|higherThan|if|import|in|indirect|infix|init|inout|internal|is|isolated|lazy|left|let|lowerThan|mutating|none|nonisolated|nonmutating|open|operator|optional|override|postfix|precedencegroup|prefix|private|protocol|public|repeat|required|rethrows|return|right|safe|self|set|some|static|struct|subscript|super|switch|throw|throws|try|typealias|unowned|unsafe|var|weak|where|while|willSet)\b/,
		'boolean': /\b(?:false|true)\b/,
		'nil': {
			pattern: /\bnil\b/,
			alias: 'constant'
		},

		'short-argument': /\$\d+\b/,
		'omit': {
			pattern: /\b_\b/,
			alias: 'keyword'
		},
		'number': /\b(?:[\d_]+(?:\.[\de_]+)?|0x[a-f0-9_]+(?:\.[a-f0-9p_]+)?|0b[01_]+|0o[0-7_]+)\b/i,

		// A class name must start with an upper-case letter and be either 1 letter long or contain a lower-case letter.
		'class-name': /\b[A-Z](?:[A-Z_\d]*[a-z]\w*)?\b/,
		'function': /\b[a-z_]\w*(?=\s*\()/i,
		'constant': /\b(?:[A-Z_]{2,}|k[A-Z][A-Za-z_]+)\b/,

		// Operators are generic in Swift. Developers can even create new operators (e.g. +++).
		// https://docs.swift.org/swift-book/ReferenceManual/zzSummaryOfTheGrammar.html#ID481
		// This regex only supports ASCII operators.
		'operator': /[-+*/%=!<>&|^~?]+|\.[.\-+*/%=!<>&|^~?]+/,
		'punctuation': /[{}[\]();,.:\\]/
	};

	Prism.languages.swift['string-literal'].forEach(function (rule) {
		rule.inside['interpolation'].inside = Prism.languages.swift;
	});

	var prismTypescript = {};

	var hasRequiredPrismTypescript;

	function requirePrismTypescript () {
		if (hasRequiredPrismTypescript) return prismTypescript;
		hasRequiredPrismTypescript = 1;
		(function (Prism) {

			Prism.languages.typescript = Prism.languages.extend('javascript', {
				'class-name': {
					pattern: /(\b(?:class|extends|implements|instanceof|interface|new|type)\s+)(?!keyof\b)(?!\s)[_$a-zA-Z\xA0-\uFFFF](?:(?!\s)[$\w\xA0-\uFFFF])*(?:\s*<(?:[^<>]|<(?:[^<>]|<[^<>]*>)*>)*>)?/,
					lookbehind: true,
					greedy: true,
					inside: null // see below
				},
				'builtin': /\b(?:Array|Function|Promise|any|boolean|console|never|number|string|symbol|unknown)\b/,
			});

			// The keywords TypeScript adds to JavaScript
			Prism.languages.typescript.keyword.push(
				/\b(?:abstract|declare|is|keyof|readonly|require)\b/,
				// keywords that have to be followed by an identifier
				/\b(?:asserts|infer|interface|module|namespace|type)\b(?=\s*(?:[{_$a-zA-Z\xA0-\uFFFF]|$))/,
				// This is for `import type *, {}`
				/\btype\b(?=\s*(?:[\{*]|$))/
			);

			// doesn't work with TS because TS is too complex
			delete Prism.languages.typescript['parameter'];
			delete Prism.languages.typescript['literal-property'];

			// a version of typescript specifically for highlighting types
			var typeInside = Prism.languages.extend('typescript', {});
			delete typeInside['class-name'];

			Prism.languages.typescript['class-name'].inside = typeInside;

			Prism.languages.insertBefore('typescript', 'function', {
				'decorator': {
					pattern: /@[$\w\xA0-\uFFFF]+/,
					inside: {
						'at': {
							pattern: /^@/,
							alias: 'operator'
						},
						'function': /^[\s\S]+/
					}
				},
				'generic-function': {
					// e.g. foo<T extends "bar" | "baz">( ...
					pattern: /#?(?!\s)[_$a-zA-Z\xA0-\uFFFF](?:(?!\s)[$\w\xA0-\uFFFF])*\s*<(?:[^<>]|<(?:[^<>]|<[^<>]*>)*>)*>(?=\s*\()/,
					greedy: true,
					inside: {
						'function': /^#?(?!\s)[_$a-zA-Z\xA0-\uFFFF](?:(?!\s)[$\w\xA0-\uFFFF])*/,
						'generic': {
							pattern: /<[\s\S]+/, // everything after the first <
							alias: 'class-name',
							inside: typeInside
						}
					}
				}
			});

			Prism.languages.ts = Prism.languages.typescript;

		}(Prism));
		return prismTypescript;
	}

	requirePrismTypescript();

	var prismJava = {};

	var hasRequiredPrismJava;

	function requirePrismJava () {
		if (hasRequiredPrismJava) return prismJava;
		hasRequiredPrismJava = 1;
		(function (Prism) {

			var keywords = /\b(?:abstract|assert|boolean|break|byte|case|catch|char|class|const|continue|default|do|double|else|enum|exports|extends|final|finally|float|for|goto|if|implements|import|instanceof|int|interface|long|module|native|new|non-sealed|null|open|opens|package|permits|private|protected|provides|public|record(?!\s*[(){}[\]<>=%~.:,;?+\-*/&|^])|requires|return|sealed|short|static|strictfp|super|switch|synchronized|this|throw|throws|to|transient|transitive|try|uses|var|void|volatile|while|with|yield)\b/;

			// full package (optional) + parent classes (optional)
			var classNamePrefix = /(?:[a-z]\w*\s*\.\s*)*(?:[A-Z]\w*\s*\.\s*)*/.source;

			// based on the java naming conventions
			var className = {
				pattern: RegExp(/(^|[^\w.])/.source + classNamePrefix + /[A-Z](?:[\d_A-Z]*[a-z]\w*)?\b/.source),
				lookbehind: true,
				inside: {
					'namespace': {
						pattern: /^[a-z]\w*(?:\s*\.\s*[a-z]\w*)*(?:\s*\.)?/,
						inside: {
							'punctuation': /\./
						}
					},
					'punctuation': /\./
				}
			};

			Prism.languages.java = Prism.languages.extend('clike', {
				'string': {
					pattern: /(^|[^\\])"(?:\\.|[^"\\\r\n])*"/,
					lookbehind: true,
					greedy: true
				},
				'class-name': [
					className,
					{
						// variables, parameters, and constructor references
						// this to support class names (or generic parameters) which do not contain a lower case letter (also works for methods)
						pattern: RegExp(/(^|[^\w.])/.source + classNamePrefix + /[A-Z]\w*(?=\s+\w+\s*[;,=()]|\s*(?:\[[\s,]*\]\s*)?::\s*new\b)/.source),
						lookbehind: true,
						inside: className.inside
					},
					{
						// class names based on keyword
						// this to support class names (or generic parameters) which do not contain a lower case letter (also works for methods)
						pattern: RegExp(/(\b(?:class|enum|extends|implements|instanceof|interface|new|record|throws)\s+)/.source + classNamePrefix + /[A-Z]\w*\b/.source),
						lookbehind: true,
						inside: className.inside
					}
				],
				'keyword': keywords,
				'function': [
					Prism.languages.clike.function,
					{
						pattern: /(::\s*)[a-z_]\w*/,
						lookbehind: true
					}
				],
				'number': /\b0b[01][01_]*L?\b|\b0x(?:\.[\da-f_p+-]+|[\da-f_]+(?:\.[\da-f_p+-]+)?)\b|(?:\b\d[\d_]*(?:\.[\d_]*)?|\B\.\d[\d_]*)(?:e[+-]?\d[\d_]*)?[dfl]?/i,
				'operator': {
					pattern: /(^|[^.])(?:<<=?|>>>?=?|->|--|\+\+|&&|\|\||::|[?:~]|[-+*/%&|^!=<>]=?)/m,
					lookbehind: true
				},
				'constant': /\b[A-Z][A-Z_\d]+\b/
			});

			Prism.languages.insertBefore('java', 'string', {
				'triple-quoted-string': {
					// http://openjdk.java.net/jeps/355#Description
					pattern: /"""[ \t]*[\r\n](?:(?:"|"")?(?:\\.|[^"\\]))*"""/,
					greedy: true,
					alias: 'string'
				},
				'char': {
					pattern: /'(?:\\.|[^'\\\r\n]){1,6}'/,
					greedy: true
				}
			});

			Prism.languages.insertBefore('java', 'class-name', {
				'annotation': {
					pattern: /(^|[^.])@\w+(?:\s*\.\s*\w+)*/,
					lookbehind: true,
					alias: 'punctuation'
				},
				'generics': {
					pattern: /<(?:[\w\s,.?]|&(?!&)|<(?:[\w\s,.?]|&(?!&)|<(?:[\w\s,.?]|&(?!&)|<(?:[\w\s,.?]|&(?!&))*>)*>)*>)*>/,
					inside: {
						'class-name': className,
						'keyword': keywords,
						'punctuation': /[<>(),.:]/,
						'operator': /[?&|]/
					}
				},
				'import': [
					{
						pattern: RegExp(/(\bimport\s+)/.source + classNamePrefix + /(?:[A-Z]\w*|\*)(?=\s*;)/.source),
						lookbehind: true,
						inside: {
							'namespace': className.inside.namespace,
							'punctuation': /\./,
							'operator': /\*/,
							'class-name': /\w+/
						}
					},
					{
						pattern: RegExp(/(\bimport\s+static\s+)/.source + classNamePrefix + /(?:\w+|\*)(?=\s*;)/.source),
						lookbehind: true,
						alias: 'static',
						inside: {
							'namespace': className.inside.namespace,
							'static': /\b\w+$/,
							'punctuation': /\./,
							'operator': /\*/,
							'class-name': /\w+/
						}
					}
				],
				'namespace': {
					pattern: RegExp(
						/(\b(?:exports|import(?:\s+static)?|module|open|opens|package|provides|requires|to|transitive|uses|with)\s+)(?!<keyword>)[a-z]\w*(?:\.[a-z]\w*)*\.?/
							.source.replace(/<keyword>/g, function () { return keywords.source; })),
					lookbehind: true,
					inside: {
						'punctuation': /\./,
					}
				}
			});
		}(Prism));
		return prismJava;
	}

	requirePrismJava();

	var prismCpp = {};

	var hasRequiredPrismCpp;

	function requirePrismCpp () {
		if (hasRequiredPrismCpp) return prismCpp;
		hasRequiredPrismCpp = 1;
		(function (Prism) {

			var keyword = /\b(?:alignas|alignof|asm|auto|bool|break|case|catch|char|char16_t|char32_t|char8_t|class|co_await|co_return|co_yield|compl|concept|const|const_cast|consteval|constexpr|constinit|continue|decltype|default|delete|do|double|dynamic_cast|else|enum|explicit|export|extern|final|float|for|friend|goto|if|import|inline|int|int16_t|int32_t|int64_t|int8_t|long|module|mutable|namespace|new|noexcept|nullptr|operator|override|private|protected|public|register|reinterpret_cast|requires|return|short|signed|sizeof|static|static_assert|static_cast|struct|switch|template|this|thread_local|throw|try|typedef|typeid|typename|uint16_t|uint32_t|uint64_t|uint8_t|union|unsigned|using|virtual|void|volatile|wchar_t|while)\b/;
			var modName = /\b(?!<keyword>)\w+(?:\s*\.\s*\w+)*\b/.source.replace(/<keyword>/g, function () { return keyword.source; });

			Prism.languages.cpp = Prism.languages.extend('c', {
				'class-name': [
					{
						pattern: RegExp(/(\b(?:class|concept|enum|struct|typename)\s+)(?!<keyword>)\w+/.source
							.replace(/<keyword>/g, function () { return keyword.source; })),
						lookbehind: true
					},
					// This is intended to capture the class name of method implementations like:
					//   void foo::bar() const {}
					// However! The `foo` in the above example could also be a namespace, so we only capture the class name if
					// it starts with an uppercase letter. This approximation should give decent results.
					/\b[A-Z]\w*(?=\s*::\s*\w+\s*\()/,
					// This will capture the class name before destructors like:
					//   Foo::~Foo() {}
					/\b[A-Z_]\w*(?=\s*::\s*~\w+\s*\()/i,
					// This also intends to capture the class name of method implementations but here the class has template
					// parameters, so it can't be a namespace (until C++ adds generic namespaces).
					/\b\w+(?=\s*<(?:[^<>]|<(?:[^<>]|<[^<>]*>)*>)*>\s*::\s*\w+\s*\()/
				],
				'keyword': keyword,
				'number': {
					pattern: /(?:\b0b[01']+|\b0x(?:[\da-f']+(?:\.[\da-f']*)?|\.[\da-f']+)(?:p[+-]?[\d']+)?|(?:\b[\d']+(?:\.[\d']*)?|\B\.[\d']+)(?:e[+-]?[\d']+)?)[ful]{0,4}/i,
					greedy: true
				},
				'operator': />>=?|<<=?|->|--|\+\+|&&|\|\||[?:~]|<=>|[-+*/%&|^!=<>]=?|\b(?:and|and_eq|bitand|bitor|not|not_eq|or|or_eq|xor|xor_eq)\b/,
				'boolean': /\b(?:false|true)\b/
			});

			Prism.languages.insertBefore('cpp', 'string', {
				'module': {
					// https://en.cppreference.com/w/cpp/language/modules
					pattern: RegExp(
						/(\b(?:import|module)\s+)/.source +
						'(?:' +
						// header-name
						/"(?:\\(?:\r\n|[\s\S])|[^"\\\r\n])*"|<[^<>\r\n]*>/.source +
						'|' +
						// module name or partition or both
						/<mod-name>(?:\s*:\s*<mod-name>)?|:\s*<mod-name>/.source.replace(/<mod-name>/g, function () { return modName; }) +
						')'
					),
					lookbehind: true,
					greedy: true,
					inside: {
						'string': /^[<"][\s\S]+/,
						'operator': /:/,
						'punctuation': /\./
					}
				},
				'raw-string': {
					pattern: /R"([^()\\ ]{0,16})\([\s\S]*?\)\1"/,
					alias: 'string',
					greedy: true
				}
			});

			Prism.languages.insertBefore('cpp', 'keyword', {
				'generic-function': {
					pattern: /\b(?!operator\b)[a-z_]\w*\s*<(?:[^<>]|<[^<>]*>)*>(?=\s*\()/i,
					inside: {
						'function': /^\w+/,
						'generic': {
							pattern: /<[\s\S]+/,
							alias: 'class-name',
							inside: Prism.languages.cpp
						}
					}
				}
			});

			Prism.languages.insertBefore('cpp', 'operator', {
				'double-colon': {
					pattern: /::/,
					alias: 'punctuation'
				}
			});

			Prism.languages.insertBefore('cpp', 'class-name', {
				// the base clause is an optional list of parent classes
				// https://en.cppreference.com/w/cpp/language/class
				'base-clause': {
					pattern: /(\b(?:class|struct)\s+\w+\s*:\s*)[^;{}"'\s]+(?:\s+[^;{}"'\s]+)*(?=\s*[;{])/,
					lookbehind: true,
					greedy: true,
					inside: Prism.languages.extend('cpp', {})
				}
			});

			Prism.languages.insertBefore('inside', 'double-colon', {
				// All untokenized words that are not namespaces should be class names
				'class-name': /\b[a-z_]\w*\b(?!\s*::)/i
			}, Prism.languages.cpp['base-clause']);

		}(Prism));
		return prismCpp;
	}

	requirePrismCpp();

	/**
	 * Copyright (c) Meta Platforms, Inc. and affiliates.
	 *
	 * This source code is licensed under the MIT license found in the
	 * LICENSE file in the root directory of this source tree.
	 *
	 */

	!function(e){e.languages.diff={coord:[/^(?:\*{3}|-{3}|\+{3}).*$/m,/^@@.*@@$/m,/^\d.*$/m]};var t={"deleted-sign":"-","deleted-arrow":"<","inserted-sign":"+","inserted-arrow":">",unchanged:" ",diff:"!"};Object.keys(t).forEach(function(n){var r=t[n],i=[];/^\w+$/.test(n)||i.push(/\w+/.exec(n)[0]),"diff"===n&&i.push("bold"),e.languages.diff[n]={pattern:RegExp("^(?:["+r+"].*(?:\r\n?|\n|(?![\\s\\S])))+","m"),alias:i,inside:{line:{pattern:/(.)(?=[\s\S]).*(?:\r\n?|\n)?/,lookbehind:true},prefix:{pattern:/[\s\S]/,alias:/\w+/.exec(n)[0]}}};}),Object.defineProperty(e.languages.diff,"PREFIXES",{value:t});}(Prism),Prism.languages.go=Prism.languages.extend("clike",{string:{pattern:/(^|[^\\])"(?:\\.|[^"\\\r\n])*"|`[^`]*`/,lookbehind:true,greedy:true},keyword:/\b(?:break|case|chan|const|continue|default|defer|else|fallthrough|for|func|go(?:to)?|if|import|interface|map|package|range|return|select|struct|switch|type|var)\b/,boolean:/\b(?:_|false|iota|nil|true)\b/,number:[/\b0(?:b[01_]+|o[0-7_]+)i?\b/i,/\b0x(?:[a-f\d_]+(?:\.[a-f\d_]*)?|\.[a-f\d_]+)(?:p[+-]?\d+(?:_\d+)*)?i?(?!\w)/i,/(?:\b\d[\d_]*(?:\.[\d_]*)?|\B\.\d[\d_]*)(?:e[+-]?[\d_]+)?i?(?!\w)/i],operator:/[*\/%^!=]=?|\+[=+]?|-[=-]?|\|[=|]?|&(?:=|&|\^=?)?|>(?:>=?|=)?|<(?:<=?|=|-)?|:=|\.\.\./,builtin:/\b(?:append|bool|byte|cap|close|complex|complex(?:64|128)|copy|delete|error|float(?:32|64)|u?int(?:8|16|32|64)?|imag|len|make|new|panic|print(?:ln)?|real|recover|rune|string|uintptr)\b/}),Prism.languages.insertBefore("go","string",{char:{pattern:/'(?:\\.|[^'\\\r\n]){0,10}'/,greedy:true}}),delete Prism.languages.go["class-name"];

	/**
	 * Copyright (c) Meta Platforms, Inc. and affiliates.
	 *
	 * This source code is licensed under the MIT license found in the
	 * LICENSE file in the root directory of this source tree.
	 *
	 */

	const tt$1=/^(\d+(?:\.\d+)?)px$/,nt$1={BOTH:3,COLUMN:2,NO_STATUS:0,ROW:1};let ot$1 = class ot extends Di{__colSpan;__rowSpan;__headerState;__width;__backgroundColor;__verticalAlign;static getType(){return "tablecell"}static clone(e){return new ot(e.__headerState,e.__colSpan,e.__width,e.__key)}afterCloneFrom(e){super.afterCloneFrom(e),this.__rowSpan=e.__rowSpan,this.__backgroundColor=e.__backgroundColor,this.__verticalAlign=e.__verticalAlign,this.__colSpan=e.__colSpan,this.__headerState=e.__headerState,this.__width=e.__width;}static importDOM(){return {td:e=>({conversion:rt$1,priority:0}),th:e=>({conversion:rt$1,priority:0})}}static importJSON(e){return st$1().updateFromJSON(e)}updateFromJSON(e){return super.updateFromJSON(e).setHeaderStyles(e.headerState).setColSpan(e.colSpan||1).setRowSpan(e.rowSpan||1).setWidth(e.width||void 0).setBackgroundColor(e.backgroundColor||null).setVerticalAlign(e.verticalAlign||void 0)}constructor(e=nt$1.NO_STATUS,t=1,n,o){super(o),this.__colSpan=t,this.__rowSpan=1,this.__headerState=e,this.__width=n,this.__backgroundColor=null,this.__verticalAlign=void 0;}createDOM(e){const o=Xl().createElement(this.getTag());return this.__width&&(o.style.width=`${this.__width}px`),this.__colSpan>1&&(o.colSpan=this.__colSpan),this.__rowSpan>1&&(o.rowSpan=this.__rowSpan),null!==this.__backgroundColor&&(o.style.backgroundColor=this.__backgroundColor),lt$1(this.__verticalAlign)&&(o.style.verticalAlign=this.__verticalAlign),vu(o,e.theme.tableCell,this.hasHeader()&&e.theme.tableCellHeader),o}exportDOM(e){const t=super.exportDOM(e);if(dc(t.element)){const e=t.element;e.setAttribute("data-temporary-table-cell-lexical-key",this.getKey()),e.style.border="1px solid black",this.__colSpan>1&&(e.colSpan=this.__colSpan),this.__rowSpan>1&&(e.rowSpan=this.__rowSpan),e.style.width=`${this.getWidth()||75}px`,e.style.verticalAlign=this.getVerticalAlign()||"top",e.style.textAlign="start",null===this.__backgroundColor&&this.hasHeader()&&(e.style.backgroundColor="#f2f3f5");}return t}exportJSON(){return {...super.exportJSON(),...lt$1(this.__verticalAlign)&&{verticalAlign:this.__verticalAlign},backgroundColor:this.getBackgroundColor(),colSpan:this.__colSpan,headerState:this.__headerState,rowSpan:this.__rowSpan,width:this.getWidth()}}getColSpan(){return this.getLatest().__colSpan}setColSpan(e){const t=this.getWritable();return t.__colSpan=e,t}getRowSpan(){return this.getLatest().__rowSpan}setRowSpan(e){const t=this.getWritable();return t.__rowSpan=e,t}getTag(){return this.hasHeader()?"th":"td"}setHeaderStyles(e,t=nt$1.BOTH){const n=this.getWritable();return n.__headerState=e&t|n.__headerState&~t,n}getHeaderStyles(){return this.getLatest().__headerState}setWidth(e){const t=this.getWritable();return t.__width=e,t}getWidth(){return this.getLatest().__width}getBackgroundColor(){return this.getLatest().__backgroundColor}setBackgroundColor(e){const t=this.getWritable();return t.__backgroundColor=e,t}getVerticalAlign(){return this.getLatest().__verticalAlign}setVerticalAlign(e){const t=this.getWritable();return t.__verticalAlign=e||void 0,t}toggleHeaderStyle(e){const t=this.getWritable();return (t.__headerState&e)===e?t.__headerState-=e:t.__headerState+=e,t}hasHeaderState(e){return (this.getHeaderStyles()&e)===e}hasHeader(){return this.getLatest().__headerState!==nt$1.NO_STATUS}updateDOM(e){return e.__headerState!==this.__headerState||e.__width!==this.__width||e.__colSpan!==this.__colSpan||e.__rowSpan!==this.__rowSpan||e.__backgroundColor!==this.__backgroundColor||e.__verticalAlign!==this.__verticalAlign}isShadowRoot(){return  true}collapseAtStart(){return  true}canBeEmpty(){return  false}canIndent(){return  false}};function lt$1(e){return "middle"===e||"bottom"===e}function rt$1(e){const t=e,n=e.nodeName.toLowerCase();let c;tt$1.test(t.style.width)&&(c=parseFloat(t.style.width));let a=nt$1.NO_STATUS;if("th"===n){const e=t.getAttribute("scope");if("col"===e)a=nt$1.COLUMN;else if("row"===e)a=nt$1.ROW;else {const e=t.parentElement,n=dc(e)&&"tr"===e.nodeName.toLowerCase()&&dc(e.parentElement)&&("thead"===e.parentElement.nodeName.toLowerCase()||0===e.rowIndex),l=0===t.cellIndex;n&&(a|=nt$1.ROW),l&&(a|=nt$1.COLUMN),a===nt$1.NO_STATUS&&(a=nt$1.ROW);}}const u=st$1(a,t.colSpan,c);u.__rowSpan=t.rowSpan;const h=t.style.backgroundColor;""!==h&&(u.__backgroundColor=h);const d=t.style.verticalAlign;lt$1(d)&&(u.__verticalAlign=d);const f=t.style,g=(f&&f.textDecoration||"").split(" "),m="700"===f.fontWeight||"bold"===f.fontWeight,p=g.includes("line-through"),C="italic"===f.fontStyle,_=g.includes("underline"),S=f.color;return {after:e=>{const t=[];let n=null;const o=()=>{if(n){const e=n.getFirstChild();qi(e)&&1===n.getChildrenSize()&&e.remove();}};for(const c of e)if(Fl(c)||Xo(c)||qi(c)){if(Xo(c)&&(m&&c.toggleFormat("bold"),p&&c.toggleFormat("strikethrough"),C&&c.toggleFormat("italic"),_&&c.toggleFormat("underline"),S)){const e=c.getStyle();e.includes("color:")||c.setStyle(e+`color: ${S};`);}n?n.append(c):(n=ts().append(c),t.push(n));}else t.push(c),o(),n=null;return o(),0===t.length&&t.push(ts()),t},node:u}}function st$1(e=nt$1.NO_STATUS,t=1,n){return Bl(new ot$1(e,t,n))}function it$1(e){return e instanceof ot$1}const ct$1=/* @__PURE__ */Fe$3("INSERT_TABLE_COMMAND");function at$1(e,...t){const n=new URL("https://lexical.dev/docs/error"),o=new URLSearchParams;o.append("code",e);for(const e of t)o.append("v",e);throw n.search=o.toString(),Error(`Minified Lexical error #${e}; visit ${n.toString()} for the full message or use the non-minified dev environment for full errors and additional helpful warnings.`)}let ut$1 = class ut extends Di{__height;static getType(){return "tablerow"}static clone(e){return new ut(e.__height,e.__key)}afterCloneFrom(e){super.afterCloneFrom(e),this.__height=e.__height;}static importDOM(){return {tr:e=>({conversion:ht$1,priority:0})}}static importJSON(e){return dt$1().updateFromJSON(e)}updateFromJSON(e){return super.updateFromJSON(e).setHeight(e.height)}constructor(e,t){super(t),this.__height=e;}exportJSON(){const e=this.getHeight();return {...super.exportJSON(),...void 0===e?void 0:{height:e}}}createDOM(e){const o=Xl().createElement("tr");return this.__height&&(o.style.height=`${this.__height}px`),vu(o,e.theme.tableRow),o}extractWithChild(e,t,n){return "html"===n}isShadowRoot(){return  true}setHeight(e){const t=this.getWritable();return t.__height=e,t}getHeight(){return this.getLatest().__height}updateDOM(e){return e.__height!==this.__height}canBeEmpty(){return  false}canIndent(){return  false}};function ht$1(e){const t=e;let n;return tt$1.test(t.style.height)&&(n=parseFloat(t.style.height)),{after:e=>Wt$3(e,it$1),node:dt$1(n)}}function dt$1(e){return Bl(new ut$1(e))}function ft$1(e){return e instanceof ut$1}function gt$1(e,t,n=true){const o=no();for(let l=0;l<e;l++){const e=dt$1();for(let o=0;o<t;o++){let t=nt$1.NO_STATUS;"object"==typeof n?(0===l&&n.rows&&(t|=nt$1.ROW),0===o&&n.columns&&(t|=nt$1.COLUMN)):n&&(0===l&&(t|=nt$1.ROW),0===o&&(t|=nt$1.COLUMN));const r=st$1(t),s=ts();s.append(Go()),r.append(s),e.append(r);}o.append(e);}return o}function Ct$1(e){const t=Jc(e,e=>oo(e));if(oo(t))return t;throw new Error("Expected table cell to be inside of table.")}const Nt=(e,t)=>e===nt$1.BOTH||e===t?t:nt$1.NO_STATUS;function Tt$1(e,t=true){const[,,n]=Ut$1(e),[o,l]=Dt$1(n,e,e),r=o[0].length,{startRow:s}=l;let c=null;if(t){const t=s+e.__rowSpan-1,l=o[t],a=dt$1();for(let e=0;e<r;e++){const{cell:n,startRow:o}=l[e];if(o+n.__rowSpan-1<=t){const t=l[e].cell.__headerState,n=Nt(t,nt$1.COLUMN);a.append(st$1(n).append(ts()));}else n.setRowSpan(n.__rowSpan+1);}const u=n.getChildAtIndex(t);ft$1(u)||at$1(256),u.insertAfter(a),c=a;}else {const e=s,t=o[e],l=dt$1();for(let n=0;n<r;n++){const{cell:o,startRow:r}=t[n];if(r===e){const e=t[n].cell.__headerState,o=Nt(e,nt$1.COLUMN);l.append(st$1(o).append(ts()));}else o.setRowSpan(o.__rowSpan+1);}const a=n.getChildAtIndex(e);ft$1(a)||at$1(257),a.insertBefore(l),c=l;}return c}function At$1(e,t=true,n=true){const[,,o]=Ut$1(e),[l,r]=Dt$1(o,e,e),s=l.length,{startColumn:c}=r,a=t?c+e.__colSpan-1:c-1,u=o.getFirstChild();ft$1(u)||at$1(120);let h=null;function d(e=nt$1.NO_STATUS){const t=st$1(e).append(ts());return null===h&&(h=t),t}let f=u;e:for(let e=0;e<s;e++){if(0!==e){const e=f.getNextSibling();ft$1(e)||at$1(121),f=e;}const t=l[e],n=t[a<0?0:a].cell.__headerState,o=Nt(n,nt$1.ROW);if(a<0){zt$1(f,d(o));continue}const{cell:r,startColumn:s,startRow:i}=t[a];if(s+r.__colSpan-1<=a){let n=r,l=i,s=a;for(;l!==e&&n.__rowSpan>1;){if(s-=r.__colSpan,!(s>=0)){f.append(d(o));continue e}{const{cell:e,startRow:o}=t[s];n=e,l=o;}}n.insertAfter(d(o));}else r.setColSpan(r.__colSpan+1);}null!==h&&n&&Wt$1(h);const g=o.getColWidths();if(g){const e=[...g],t=a<0?0:a,n=e[t];e.splice(t,0,n),o.setColWidths(e);}return h}function Wt$1(e){const t=e.getFirstDescendant();null==t?e.selectStart():t.getParentOrThrow().selectStart();}function zt$1(e,t){const n=e.getFirstChild();null!==n?n.insertBefore(t):e.append(t);}function Ht$1(e){if(0===e.length)return null;const t=Ct$1(e[0]),[n]=It$1(t,null,null);let o=1/0,l=-1/0,r=1/0,s=-1/0;const c=new Set;for(const t of n)for(const n of t){if(!n||!n.cell)continue;const t=n.cell.getKey();if(!c.has(t)&&e.some(e=>e.is(n.cell))){c.add(t);const e=n.startRow,i=n.startColumn,a=n.cell.__rowSpan||1,u=n.cell.__colSpan||1;o=Math.min(o,e),l=Math.max(l,e+a-1),r=Math.min(r,i),s=Math.max(s,i+u-1);}}if(o===1/0||r===1/0)return null;const a=l-o+1,u=s-r+1,h=n[o][r];if(!h.cell)return null;const d=h.cell;d.setColSpan(u),d.setRowSpan(a);const f=new Set([d.getKey()]);for(let e=o;e<=l;e++)for(let t=r;t<=s;t++){const o=n[e][t];if(!o.cell)continue;const l=o.cell,r=l.getKey();if(!f.has(r)){f.add(r);Bt$1(l)||d.append(...l.getChildren()),l.remove();}}return 0===d.getChildrenSize()&&d.append(ts()),d}function Bt$1(e){if(1!==e.getChildrenSize())return  false;const t=e.getFirstChildOrThrow();return !(!es(t)||!t.isEmpty())}function Pt$1(e){const[t,n,o]=Ut$1(e),l=t.__colSpan,r=t.__rowSpan;if(1===l&&1===r)return;const[s,c]=Dt$1(o,t,t),{startColumn:a,startRow:u}=c,h=t.__headerState&nt$1.COLUMN,d=Array.from({length:l},(e,t)=>{let n=h;for(let e=0;0!==n&&e<s.length;e++)n&=s[e][t+a].cell.__headerState;return n}),f=t.__headerState&nt$1.ROW,g=Array.from({length:r},(e,t)=>{let n=f;for(let e=0;0!==n&&e<s[0].length;e++)n&=s[t+u][e].cell.__headerState;return n});if(l>1){for(let e=1;e<l;e++)t.insertAfter(st$1(d[e]|g[0]).append(ts()));t.setColSpan(1);}if(r>1){let e;for(let t=1;t<r;t++){const o=u+t,r=s[o];e=(e||n).getNextSibling(),ft$1(e)||at$1(125);let c=null;for(let e=0;e<a;e++){const t=r[e],n=t.cell;t.startRow===o&&(c=n),n.__colSpan>1&&(e+=n.__colSpan-1);}if(null===c)for(let n=l-1;n>=0;n--)zt$1(e,st$1(d[n]|g[t]).append(ts()));else for(let e=l-1;e>=0;e--)c.insertAfter(st$1(d[e]|g[t]).append(ts()));}t.setRowSpan(1);}}function Dt$1(e,t,n){const[o,l,r]=It$1(e,t,n);return null===l&&at$1(207),null===r&&at$1(208),[o,l,r]}function It$1(e,t,n){const o=[];let l=null,r=null;function s(e){let t=o[e];return void 0===t&&(o[e]=t=[]),t}const i=e.getChildren();for(let e=0;e<i.length;e++){const o=i[e];ft$1(o)||at$1(209);const c=s(e);for(let a=o.getFirstChild(),u=0;null!=a;a=a.getNextSibling()){for(it$1(a)||at$1(147);void 0!==c[u];)u++;const o={cell:a,startColumn:u,startRow:e},{__rowSpan:h,__colSpan:d}=a;for(let t=0;t<h&&!(e+t>=i.length);t++){const n=s(e+t);for(let e=0;e<d;e++)n[u+e]=o;}null!==t&&null===l&&t.is(a)&&(l=o),null!==n&&null===r&&n.is(a)&&(r=o);}}return [o,l,r]}function Ut$1(e){let t;if(e instanceof ot$1)t=e;else if("__type"in e){const n=Jc(e,it$1);it$1(n)||at$1(148),t=n;}else {const n=Jc(e.getNode(),it$1);it$1(n)||at$1(148),t=n;}const n=t.getParent();ft$1(n)||at$1(149);const o=n.getParent();return oo(o)||at$1(210),[t,n,o]}function Xt$1(e,t,n){let o,l=Math.min(t.startColumn,n.startColumn),r=Math.min(t.startRow,n.startRow),s=Math.max(t.startColumn+t.cell.__colSpan-1,n.startColumn+n.cell.__colSpan-1),i=Math.max(t.startRow+t.cell.__rowSpan-1,n.startRow+n.cell.__rowSpan-1);do{o=false;for(let t=0;t<e.length;t++)for(let n=0;n<e[0].length;n++){const c=e[t][n];if(!c)continue;const a=c.startColumn+c.cell.__colSpan-1,u=c.startRow+c.cell.__rowSpan-1,h=c.startColumn<=s&&a>=l,d=c.startRow<=i&&u>=r;if(h&&d){const e=Math.min(l,c.startColumn),t=Math.max(s,a),n=Math.min(r,c.startRow),h=Math.max(i,u);e===l&&t===s&&n===r&&h===i||(l=e,s=t,r=n,i=h,o=true);}}}while(o);return {maxColumn:s,maxRow:i,minColumn:l,minRow:r}}function jt$1(e){const[t,,n]=Ut$1(e),o=n.getChildren().filter(ft$1),l=o.length,r=o[0].getChildren().length,s=new Array(l);for(let e=0;e<l;e++)s[e]=new Array(r);for(let e=0;e<l;e++){const n=o[e].getChildren().filter(it$1);let l=0;for(let o=0;o<n.length;o++){for(;s[e][l];)l++;const r=n[o],i=r.__rowSpan||1,c=r.__colSpan||1;for(let t=0;t<i;t++)for(let n=0;n<c;n++)s[e+t][l+n]=r;if(t===r)return {colSpan:c,columnIndex:l,rowIndex:e,rowSpan:i};l+=c;}}return null}function Vt$1(e,t){const n=t.getStartEndPoints(),o=tn(t);if(null===n)return  false;const[l,s]=n,[c,a,u]=Ut$1(l),h=Jc(s.getNode(),e=>it$1(e));if(!(it$1(c)&&it$1(h)&&ft$1(a)&&oo(u)))return  false;const[d,g,m]=Dt$1(u,c,h),[p]=It$1(e,null,null),C=d.length,_=C>0?d[0].length:0;let S=g.startRow,b=g.startColumn,w=p.length,y=w>0?p[0].length:0;if(o){const e=Xt$1(d,g,m),t=e.maxRow-e.minRow+1,n=e.maxColumn-e.minColumn+1;S=e.minRow,b=e.minColumn,w=Math.min(w,t),y=Math.min(y,n);}let N=false;const x=Math.min(C,S+w)-1,v=Math.min(_,b+y)-1,T=new Set;for(let e=S;e<=x;e++)for(let t=b;t<=v;t++){const n=d[e][t];T.has(n.cell.getKey())||(1===n.cell.__rowSpan&&1===n.cell.__colSpan||(Pt$1(n.cell),T.add(n.cell.getKey()),N=true));}let[R]=It$1(u.getWritable(),null,null);const F=w-C+S;for(let e=0;e<F;e++){Tt$1(R[C-1][0].cell);}const O=y-_+b;for(let e=0;e<O;e++){At$1(R[0][_-1].cell,true,false);}[R]=It$1(u.getWritable(),null,null);for(let e=S;e<S+w;e++)for(let t=b;t<b+y;t++){const n=e-S,o=t-b,l=p[n][o];if(l.startRow!==n||l.startColumn!==o)continue;const s=l.cell;if(1!==s.__rowSpan||1!==s.__colSpan){const n=[],o=Math.min(e+s.__rowSpan,S+w)-1,l=Math.min(t+s.__colSpan,b+y)-1;for(let r=e;r<=o;r++)for(let e=t;e<=l;e++){const t=R[r][e];n.push(t.cell);}Ht$1(n),N=true;}const{cell:c}=R[e][t],a=s.getBackgroundColor();null!=a&&c.setBackgroundColor(a);const u=c.getChildren();s.getChildren().forEach(e=>{if(Xo(e)){ts().append(e),c.append(e);}else c.append(e);}),u.forEach(e=>e.remove());}if(o&&N){const[e]=It$1(u.getWritable(),null,null);e[g.startRow][g.startColumn].cell.selectEnd();}return  true}function Zt$1(e){const[[t,n,o,l],[r,s,i,c]]=["anchor","focus"].map(t=>{const n=e[t].getNode(),o=Jc(n,it$1);it$1(o)||at$1(238,t,n.getKey(),n.getType());const l=o.getParent();ft$1(l)||at$1(239,t);const r=l.getParent();return oo(r)||at$1(240,t),[n,o,l,r]});return l.is(c)||at$1(241),{anchorCell:n,anchorNode:t,anchorRow:o,anchorTable:l,focusCell:s,focusNode:r,focusRow:i,focusTable:c}}class en{tableKey;anchor;focus;_cachedNodes;dirty;constructor(e,t,n){this.anchor=t,this.focus=n,t._selection=this,n._selection=this,this._cachedNodes=null,this.dirty=false,this.tableKey=e;}getStartEndPoints(){return [this.anchor,this.focus]}isValid(){if("root"===this.tableKey||"root"===this.anchor.key||"element"!==this.anchor.type||"root"===this.focus.key||"element"!==this.focus.type)return  false;const e=Vs(this.tableKey),t=Vs(this.anchor.key),n=Vs(this.focus.key);return null!==e&&null!==t&&null!==n}isBackward(){return this.focus.isBefore(this.anchor)}getCachedNodes(){return this._cachedNodes}setCachedNodes(e){this._cachedNodes=e;}is(e){return tn(e)&&this.tableKey===e.tableKey&&this.anchor.is(e.anchor)&&this.focus.is(e.focus)}set(e,t,n){this.dirty=this.dirty||e!==this.tableKey||t!==this.anchor.key||n!==this.focus.key,this.tableKey=e,this.anchor.key=t,this.focus.key=n,this._cachedNodes=null;}clone(){return new en(this.tableKey,or(this.anchor.key,this.anchor.offset,this.anchor.type),or(this.focus.key,this.focus.offset,this.focus.type))}isCollapsed(){return  false}extract(){return this.getNodes()}insertRawText(e){if(""===e)return;const t=(e.endsWith("\n")?e.slice(0,-1):e).split("\n").map(e=>e.split("\t")),n=no();for(const e of t){const t=dt$1();for(const n of e){const e=st$1(nt$1.NO_STATUS),o=ts();n&&o.append(Go(n)),e.append(o),t.append(e);}n.append(t);}const{anchorCell:o}=Zt$1(this);Vt$1(n,o.select(0,o.getChildrenSize()));}insertText(){}hasFormat(e){let t=0;this.getNodes().filter(it$1).forEach(e=>{const n=e.getFirstChild();es(n)&&(t|=n.getTextFormat());});const n=z$1[e];return 0!==(t&n)}insertNodes(e){const t=this.focus.getNode();Pi(t)||at$1(151);It$5(t.select(0,t.getChildrenSize())).insertNodes(e);}getShape(){const{anchorCell:e,focusCell:t}=Zt$1(this),n=jt$1(e);null===n&&at$1(153);const o=jt$1(t);null===o&&at$1(155);const l=Math.min(n.columnIndex,o.columnIndex),r=Math.max(n.columnIndex+n.colSpan-1,o.columnIndex+o.colSpan-1),s=Math.min(n.rowIndex,o.rowIndex),i=Math.max(n.rowIndex+n.rowSpan-1,o.rowIndex+o.rowSpan-1);return {fromX:Math.min(l,r),fromY:Math.min(s,i),toX:Math.max(l,r),toY:Math.max(s,i)}}getNodes(){if(!this.isValid())return [];const e=this._cachedNodes;if(null!==e)return e;const{anchorTable:t,anchorCell:n,focusCell:o}=Zt$1(this),l=o.getParents()[1];if(l!==t){if(t.isParentOf(o)){const e=l.getParent();null==e&&at$1(159),this.set(this.tableKey,o.getKey(),e.getKey());}else {const e=t.getParent();null==e&&at$1(158),this.set(this.tableKey,e.getKey(),o.getKey());}return this.getNodes()}const[r,s,i]=Dt$1(t,n,o),{minColumn:c,maxColumn:a,minRow:u,maxRow:h}=Xt$1(r,s,i),d=new Map([[t.getKey(),t]]);let f=null;for(let e=u;e<=h;e++)for(let t=c;t<=a;t++){const{cell:n}=r[e][t],o=n.getParent();ft$1(o)||at$1(160),o!==f&&(d.set(o.getKey(),o),f=o),d.has(n.getKey())||ln(n,e=>{d.set(e.getKey(),e);});}const g=Array.from(d.values());return ai()||(this._cachedNodes=g),g}getTextContent(){const e=this.getNodes().filter(e=>it$1(e));let t="";for(let n=0;n<e.length;n++){const o=e[n],l=o.__parent,r=(e[n+1]||{}).__parent;t+=o.getTextContent()+(r!==l?"\n":"\t");}return t}}function tn(e){return e instanceof en}function nn(){const e=or("root",0,"element"),t=or("root",0,"element");return new en("root",e,t)}function on(e,t,n){e.getKey(),t.getKey(),n.getKey();const o=Lr(),l=tn(o)?o.clone():nn();return l.set(e.getKey(),t.getKey(),n.getKey()),l}function ln(e,t){const n=[[e]];for(let e=n.at(-1);void 0!==e&&n.length>0;e=n.at(-1)){const o=e.pop();void 0===o?n.pop():false!==t(o)&&Pi(o)&&n.push(o.getChildren());}}function rn(e,t=Cc()){const n=Vs(e);oo(n)||at$1(231,e);const o=gn(n,t.getElementByKey(e));return null===o&&at$1(232,e),{tableElement:o,tableNode:n}}class sn{observers;nextFocus;shouldCheckSelectionForTable;constructor(){this.observers=new Map,this.nextFocus=null,this.shouldCheckSelectionForTable=null;}setNextFocus(e){this.nextFocus=e;}getAndClearNextFocus(){const{nextFocus:e}=this;return null!==e&&(this.nextFocus=null),e}setShouldCheckSelectionForTable(e){this.shouldCheckSelectionForTable=e;}getAndClearShouldCheckSelectionForTable(){const{shouldCheckSelectionForTable:e}=this;return e?(this.shouldCheckSelectionForTable=null,e):null}removeObserver(e){const t=this.observers.get(e);return void 0!==t&&(t[0].removeListeners(),this.observers.delete(e),true)}removeAllObservers(){for(const e of Array.from(this.observers.keys()))this.removeObserver(e);}$getTableNodesAndObservers(){const e=[];for(const[t,[n]]of Array.from(this.observers.entries())){const o=Vs(t);oo(o)?e.push([o,n]):this.removeObserver(t);}return e}}class cn{focusX;focusY;listenersToRemove;table;isHighlightingCells;anchorX;anchorY;tableNodeKey;anchorCell;focusCell;anchorCellNodeKey;focusCellNodeKey;editor;tableSelection;hasHijackedSelectionStyles;isSelecting;pointerType;abortController;listenerOptions;constructor(e,t){this.isHighlightingCells=false,this.anchorX=-1,this.anchorY=-1,this.focusX=-1,this.focusY=-1,this.listenersToRemove=new Set,this.tableNodeKey=t,this.editor=e,this.table={columns:0,domRows:[],rows:0},this.tableSelection=null,this.anchorCellNodeKey=null,this.focusCellNodeKey=null,this.anchorCell=null,this.focusCell=null,this.hasHijackedSelectionStyles=false,this.isSelecting=false,this.pointerType=null,this.abortController=new AbortController,this.listenerOptions={signal:this.abortController.signal},this.trackTable();}getTable(){return this.table}removeListeners(){this.abortController.abort("removeListeners"),Array.from(this.listenersToRemove).forEach(e=>e()),this.listenersToRemove.clear();}$lookup(){return rn(this.tableNodeKey,this.editor)}trackTable(){const e=new MutationObserver(e=>{this.editor.read("latest",()=>{let t=false;for(let n=0;n<e.length;n++){const o=e[n].target.nodeName;if("TABLE"===o||"TBODY"===o||"THEAD"===o||"TR"===o){t=true;break}}if(!t)return;const{tableNode:n,tableElement:o}=this.$lookup();this.table=Rn(n,o);});});this.editor.read("latest",()=>{const{tableNode:t,tableElement:n}=this.$lookup();this.table=Rn(t,n),e.observe(n,{attributes:true,childList:true,subtree:true});});}$clearHighlight(e=true){const t=this.editor;this.isHighlightingCells=false,this.anchorX=-1,this.anchorY=-1,this.focusX=-1,this.focusY=-1,this.tableSelection=null,this.anchorCellNodeKey=null,this.focusCellNodeKey=null,this.anchorCell=null,this.focusCell=null,this.hasHijackedSelectionStyles=false,this.$enableHighlightStyle();const{tableNode:n,tableElement:o}=this.$lookup();Fn(t,Rn(n,o),null),e&&null!==Lr()&&(el(null),t.dispatchCommand(Ie$2,void 0));}$enableHighlightStyle(){const e=this.editor,{tableElement:t}=this.$lookup();Tu(t,e._config.theme.tableSelection),t.classList.remove("disable-selection"),this.hasHijackedSelectionStyles=false;}$disableHighlightStyle(){const{tableElement:e}=this.$lookup();vu(e,this.editor._config.theme.tableSelection),this.hasHijackedSelectionStyles=true;}$updateTableTableSelection(e){if(null!==e){e.tableKey!==this.tableNodeKey&&at$1(233,e.tableKey,this.tableNodeKey);const t=this.editor;this.tableSelection=e,this.isHighlightingCells=true,this.$disableHighlightStyle(),this.updateDOMSelection(),Fn(t,this.table,this.tableSelection);}else this.$clearHighlight();}updateDOMSelection(){if(null!==this.anchorCell&&null!==this.focusCell){const e=Hl(this.editor._window);e&&e.rangeCount>0&&e.removeAllRanges();}}$setFocusCellForSelection(e,t=false){const n=this.editor,{tableNode:o}=this.$lookup(),l=e.x,r=e.y;if(this.focusCell=e,!this.isHighlightingCells){(t||this.anchorX!==l||this.anchorY!==r||null!=this.tableSelection&&null!=this.anchorCellNodeKey)&&(this.isHighlightingCells=true,this.$disableHighlightStyle());}if(-1!==this.focusX&&-1!==this.focusY&&l===this.focusX&&r===this.focusY)return  false;if(this.focusX=l,this.focusY=r,this.isHighlightingCells){const s=Jn(o,e.elem);if(null!=this.tableSelection&&null!=this.anchorCellNodeKey){let e=s;if(null===e&&t&&(e=o.getCellNodeFromCords(l,r,this.table)),null!==e){const t=this.$getAnchorTableCellOrThrow();return this.focusCellNodeKey=e.getKey(),this.tableSelection=on(o,t,e),el(this.tableSelection),n.dispatchCommand(Ie$2,void 0),Fn(n,this.table,this.tableSelection),true}}}return  false}$getAnchorTableCell(){const e=this.anchorCellNodeKey?Vs(this.anchorCellNodeKey):null;return it$1(e)?e:null}$getAnchorTableCellOrThrow(){const e=this.$getAnchorTableCell();return null===e&&at$1(234),e}$getFocusTableCell(){const e=this.focusCellNodeKey?Vs(this.focusCellNodeKey):null;return it$1(e)?e:null}$getFocusTableCellOrThrow(){const e=this.$getFocusTableCell();return null===e&&at$1(235),e}$setAnchorCellForSelection(e){this.isHighlightingCells=false,this.anchorCell=e,this.anchorX=e.x,this.anchorY=e.y,this.focusX=-1,this.focusY=-1,this.focusCell=null,this.focusCellNodeKey=null;const{tableNode:t}=this.$lookup(),n=Jn(t,e.elem);if(null!==n){const e=n.getKey();null!=this.tableSelection?(this.tableSelection=this.tableSelection.clone(),this.tableSelection.set(t.getKey(),e,e)):this.tableSelection=on(t,n,n),this.anchorCellNodeKey=e;}}$formatCells(e){const t=Lr();tn(t)||at$1(236);const n=Dr(),o=n.anchor,l=n.focus,r=t.getNodes().filter(it$1);r.length>0||at$1(237);const s=r[0].getFirstChild(),i=es(s)?s.getFormatFlags(e,null):null;r.forEach(t=>{o.set(t.getKey(),0,"element"),l.set(t.getKey(),t.getChildrenSize(),"element"),n.formatText(e,i);}),el(t),this.editor.dispatchCommand(Ie$2,void 0);}$clearText(){const{editor:e}=this,t=Vs(this.tableNodeKey);if(!oo(t))throw new Error("Expected TableNode.");const n=Lr();tn(n)||at$1(253);const o=n.getNodes().filter(it$1),l=t.getFirstChild(),r=t.getLastChild();if(o.length>0&&null!==l&&null!==r&&ft$1(l)&&ft$1(r)&&o[0]===l.getFirstChild()&&o[o.length-1]===r.getLastChild()){t.selectPrevious();const n=t.getParent();return t.remove(),void(zi(n)&&n.isEmpty()&&e.dispatchCommand(He$3,void 0))}o.forEach(e=>{if(Pi(e)){const t=ts(),n=Go();t.append(n),e.append(t),e.getChildren().forEach(e=>{e!==t&&e.remove();});}}),Fn(e,this.table,null),el(null),e.dispatchCommand(Ie$2,void 0);}}const an="__lexicalTableSelection";function un(e){const t=Wl(e);return oo(t)||at$1(386,e),t}const hn=40;function dn(e,t,n){const o=e=>Math.max(1,Math.ceil(Math.min(hn,e)/hn*18));return e<=t+hn?-o(t+hn-e):e>=n-hn?o(e-(n-hn)):0}function fn(e){return dc(e)&&"TABLE"===e.nodeName}function gn(e,t){if(!t)return t;const n=fn(t)?t:t.querySelector("table");return fn(n)||at$1(341,e.constructor.name,e.getType(),e.getKey(),t.nodeName),n}function mn(e){return e._window}function pn(e,t){for(let n=t,o=null;null!==n;n=n.getParent()){if(e.is(n))return o;it$1(n)&&(o=n);}return null}const Cn=[[ln$2,"down"],[sn$2,"up"],[on$2,"backward"],[en$3,"forward"]],_n=[qe$3,Ye$1,Ue$2],Sn=[un$2,dn$2];function bn(e,t){return e.registerRootListener(n=>{if(null===n)return;const o=e._window;if(null===o)return;return Pn$1(o,"pointerdown",o=>{const l=lc(o);if(0!==o.button||!hc(l)||!n.contains(l))return;const r=function(e){const t=vn(e);if(null===t)return null;let n=t.elem;for(;null!=n;){if("TABLE"===n.nodeName&&an in n&&n[an])return {cellElement:t,tableElement:n,tableObserver:n[an]};n=n.parentNode;}return null}(l);e.update(()=>{if(tn(Lr())){for(const[e]of t.observers.values())e.$clearHighlight(false);el(null),e.dispatchCommand(Ie$2,void 0);}if(!r)return;const{tableObserver:n,tableElement:l,cellElement:s}=r;!function(e,t,n,o,l,r){const s=e._window;if(!s)return;const i$1=n=>{if(l.isSelecting)return;l.isSelecting=true,null!==n&&null===l.anchorCell&&e.update(()=>{l.$setAnchorCellForSelection(n);});let i$1=t.clientX,c=t.clientY,a=null;const u=()=>{l.isSelecting=false,null!==a&&(s.cancelAnimationFrame(a),a=null),s.removeEventListener("pointerup",S),s.removeEventListener("pointermove",b);},h=(e,t)=>{const n=o.getRootNode();if(!Ks(n)&&!jl(n))return null;for(const l of n.elementsFromPoint(e,t)){const e=Tn(o,l);if(e)return e}return null},d=(t,n)=>{null===l.anchorCell&&e.update(()=>{l.$setAnchorCellForSelection(t);}),null!==l.focusCell&&t.elem===l.focusCell.elem||(r.setNextFocus({focusCell:t,override:n,tableKey:l.tableNodeKey}),e.dispatchCommand(Ie$2,void 0));},f=e=>{for(let t=o.parentElement;t;t=t.parentElement){if("x"===e?t.scrollWidth>t.clientWidth:t.scrollHeight>t.clientHeight){const n=s.getComputedStyle(t),o="x"===e?n.overflowX:n.overflowY;if("auto"===o||"scroll"===o)return t}}return null},g=(e,t,n)=>{let o,l;if(null===e)o=0,l="x"===n?s.innerWidth:s.innerHeight;else {const t=e.getBoundingClientRect();o="x"===n?t.left:t.top,l="x"===n?t.right:t.bottom;}const r=dn(t,o,l);if(0===r)return  false;if(null===e){const e="x"===n?s.scrollX:s.scrollY;return s.scrollBy("x"===n?r:0,"x"===n?0:r),("x"===n?s.scrollX:s.scrollY)!==e}if("x"===n){const t=e.scrollLeft;return e.scrollLeft+=r,e.scrollLeft!==t}const i=e.scrollTop;return e.scrollTop+=r,e.scrollTop!==i},m=(e,t)=>{let n=i$1,o=c;if(null===e)n=Math.min(Math.max(n,1),s.innerWidth-1);else {const t=e.getBoundingClientRect();n=Math.min(Math.max(n,t.left+1),t.right-1);}if(null===t)o=Math.min(Math.max(o,1),s.innerHeight-1);else {const e=t.getBoundingClientRect();o=Math.min(Math.max(o,e.top+1),e.bottom-1);}return [n,o]},p=()=>{const e=f("x");if(null!==e){const t=e.getBoundingClientRect();if(0!==dn(i$1,t.left,t.right))return  true}const t=f("y"),n=null===t?0:t.getBoundingClientRect().top,o=null===t?s.innerHeight:t.getBoundingClientRect().bottom;return 0!==dn(c,n,o)},C=()=>{if(a=null,!l.isSelecting)return;const e=f("x"),t=f("y"),n=null!==e&&g(e,i$1,"x"),o=g(t,c,"y");if(n||o){const[n,o]=m(e,t),l=h(n,o);l&&d(l,false),a=s.requestAnimationFrame(C);}},_=()=>{null===a&&"touch"!==l.pointerType&&p()&&(a=s.requestAnimationFrame(C));},S=()=>{u();},b=e=>{if(!(e=>!(1&~e.buttons))(e)&&l.isSelecting)return void u();const t=lc(e);if(!hc(t))return;i$1=e.clientX,c=e.clientY;let n=null;const r=!(i||o.contains(t));n=r?Tn(o,t):h(e.clientX,e.clientY),n&&d(n,r),_();};s.addEventListener("pointerup",S,l.listenerOptions),s.addEventListener("pointermove",b,l.listenerOptions);};l.pointerType=t.pointerType;const c=un(l.tableNodeKey),a=Kr();if(i&&t.shiftKey&&$n(a,c)&&(cr(a)||tn(a))){const e=a.anchor.getNode(),o=pn(c,a.anchor.getNode());if(o)l.$setAnchorCellForSelection(Yn(l,o)),l.$setFocusCellForSelection(n),In(t);else {(c.isBefore(e)?c.selectStart():c.selectEnd()).anchor.set(a.anchor.key,a.anchor.offset,a.anchor.type);}}else "touch"!==t.pointerType&&l.$setAnchorCellForSelection(n);i$1(n);}(e,o,s,l,n,t);});})})}function wn(e,t,n,o,l){const r=n.getRootElement(),s=mn(n);null!==r&&null!==s||at$1(246);const i=new cn(n,e.getKey()),c=gn(e,t);!function(e,t){null!==xn(e)&&at$1(205);e[an]=t;}(c,i),i.listenersToRemove.add(()=>function(e,t){xn(e)===t&&delete e[an];}(c,i));i.listenersToRemove.add(Pn$1(c,"mousedown",e=>{const t=lc(e);if(e.detail>=3&&hc(t)){null!==vn(t)&&e.preventDefault();}},i.listenerOptions));for(const[t,o]of Cn)i.listenersToRemove.add(n.registerCommand(t,t=>Dn(n,t,o,e,i,l),ss));i.listenersToRemove.add(n.registerCommand(fn$2,t=>{const n=Lr();if(tn(n)){const o=pn(e,n.focus.getNode());if(null!==o)return In(t),o.selectEnd(),true}return  false},ss));const a=t=>()=>{const n=Lr();if(!$n(n,e))return  false;if(tn(n))return i.$clearText(),true;if(cr(n)){if(!it$1(pn(e,n.anchor.getNode())))return  false;const o=n.anchor.getNode(),l=n.focus.getNode(),r=e.isParentOf(o),s=e.isParentOf(l);if(r&&!s||s&&!r)return i.$clearText(),true;const c=Jc(n.anchor.getNode(),e=>Pi(e)),a=c&&Jc(c,e=>Pi(e)&&it$1(e.getParent()));if(!Pi(a)||!Pi(c))return  false;if(t===Ye$1&&null===a.getPreviousSibling())return  true}return  false};for(const e of _n)i.listenersToRemove.add(n.registerCommand(e,a(e),ss));const g=t=>{const n=Lr();if(!tn(n)&&!cr(n))return  false;const o=e.isParentOf(n.anchor.getNode());if(o!==e.isParentOf(n.focus.getNode())){const t=o?"anchor":"focus",l=o?"focus":"anchor",{key:r,offset:s,type:i}=n[l];return e[n[t].isBefore(n[l])?"selectPrevious":"selectNext"]()[l].set(r,s,i),false}return !!$n(n,e)&&(!!tn(n)&&(t&&(t.preventDefault(),t.stopPropagation()),i.$clearText(),true))};for(const e of Sn)i.listenersToRemove.add(n.registerCommand(e,g,ss));i.listenersToRemove.add(n.registerCommand(Tn$1,e=>{const t=Lr();if(t){if(!tn(t)&&!cr(t))return  false;Dt$4(n,Mt$4(e,ClipboardEvent)?e:null,Rt$3(t));const o=g(e);return cr(t)?(t.removeText(),true):o}return  false},ss));const m=r.ownerDocument;return i.listenersToRemove.add(Pn$1(m,"paste",t=>{if(t.defaultPrevented)return;n.read("latest",()=>{const t=Lr();return r.contains(m.activeElement)&&tn(t)&&$n(t,e)})&&(t.preventDefault(),n.dispatchCommand(je$2,t));})),i.listenersToRemove.add(Pn$1(m,"copy",t=>{if(t.defaultPrevented)return;const o=lc(t);if(o===r||hc(o)&&r.contains(o))return;n.read("latest",()=>{const t=Lr();return r.contains(ic(r))&&tn(t)&&$n(t,e)})&&(t.preventDefault(),n.dispatchCommand(vn$1,t));})),i.listenersToRemove.add(n.registerCommand(Ge$2,t=>{const n=Lr();if(!$n(n,e))return  false;if(tn(n))return i.$formatCells(t),true;if(cr(n)){const e=Jc(n.anchor.getNode(),e=>it$1(e));if(!it$1(e))return  false}return  false},ss)),i.listenersToRemove.add(n.registerCommand(mn$1,t=>{const n=Lr();if(!tn(n)||!$n(n,e))return  false;const o=n.anchor.getNode(),l=n.focus.getNode();if(!it$1(o)||!it$1(l))return  false;if(function(e,t){if(tn(e)){const n=e.anchor.getNode(),o=e.focus.getNode();if(t&&n&&o){const[e]=Dt$1(t,n,o);return n.getKey()===e[0][0].cell.getKey()&&o.getKey()===e[e.length-1].at(-1).cell.getKey()}}return  false}(n,e))return e.setFormat(t),true;const[r,s,i]=Dt$1(e,o,l),c=Math.max(s.startRow+s.cell.__rowSpan-1,i.startRow+i.cell.__rowSpan-1),a=Math.max(s.startColumn+s.cell.__colSpan-1,i.startColumn+i.cell.__colSpan-1),u=Math.min(s.startRow,i.startRow),d=Math.min(s.startColumn,i.startColumn),f=new Set;for(let e=u;e<=c;e++)for(let n=d;n<=a;n++){const o=r[e][n].cell;if(f.has(o))continue;f.add(o),o.setFormat(t);const l=o.getChildren();for(let e=0;e<l.length;e++){const n=l[e];Pi(n)&&!n.isInline()&&n.setFormat(t);}}return  true},ss)),i.listenersToRemove.add(n.registerCommand(Je$3,t=>{const o=Lr();if(!$n(o,e))return  false;if(tn(o))return i.$clearHighlight(),false;if(cr(o)){const l=Jc(o.anchor.getNode(),e=>it$1(e));if(!it$1(l))return  false;if("string"==typeof t){const l=Xn(n,o,e);if(l)return Un(l,e,[Go(t)]),true}}return  false},ss)),o&&i.listenersToRemove.add(n.registerCommand(hn$2,t=>{const n=Lr();if(!cr(n)||!n.isCollapsed()||!$n(n,e))return  false;const o=Bn(n.anchor.getNode());return !(null===o||!e.is(Ln(o)))&&(In(t),function(e,t){const n="next"===t?"getNextSibling":"getPreviousSibling",o="next"===t?"getFirstChild":"getLastChild",l=e[n]();if(Pi(l))return l.selectEnd();const r=Jc(e,ft$1);null===r&&at$1(247);for(let e=r[n]();ft$1(e);e=e[n]()){const t=e[o]();if(Pi(t))return t.selectEnd()}const s=Jc(r,oo);null===s&&at$1(248);"next"===t?s.selectNext():s.selectPrevious();}(o,t.shiftKey?"previous":"next"),true)},ss)),i.listenersToRemove.add(n.registerCommand(On$2,t=>e.isSelected(),ss)),i.listenersToRemove.add(n.registerCommand(He$3,()=>{const t=Lr();if(!cr(t)||!t.isCollapsed()||!$n(t,e))return  false;const o=Xn(n,t,e);return !!o&&(Un(o,e),true)},ss)),i}function yn(e,t){const n=Lr(),o=Kr(),l=e.getAndClearNextFocus();if(null!==l){const{tableKey:t,focusCell:o}=l,r=e.observers.get(t);r||at$1(335,t);const[s]=r;if(tn(n)&&n.tableKey===s.tableNodeKey)return (o.x!==s.focusX||o.y!==s.focusY)&&(s.$setFocusCellForSelection(o),true);if(null!==s.anchorCell&&null!==s.anchorCellNodeKey&&o.elem!==s.anchorCell.elem&&null!==s.tableSelection)return s.$setFocusCellForSelection(o,true),true}const r=e.getAndClearShouldCheckSelectionForTable();if(r&&cr(o)&&cr(n)&&n.isCollapsed()){const e=Vs(r);if(oo(e)){const t=n.anchor.getNode(),o=e.getFirstChild(),l=Bn(t);if(null!==l&&ft$1(o)){const t=o.getFirstChild();if(it$1(t)&&e.is(Jc(l,n=>n.is(e)||n.is(t))))return t.selectStart(),true}}}tn(n)&&function(e,t){const n=mn(e),o=Kr();if(!t.is(o))return;const l=un(t.tableKey),r=Hl(n),s=r&&tc(r,e.getRootElement());if(r&&s&&s.anchorNode&&s.focusNode){const n=Xs(s.focusNode),o=n&&!l.isParentOf(n),i=Xs(s.anchorNode),c=i&&l.isParentOf(i);if(o&&c&&r.rangeCount>0){const n=Fr(r,e);n&&(n.anchor.set(l.getKey(),t.isBackward()?l.getChildrenSize():0,"element"),r.removeAllRanges(),el(n));}}}(t,n),cr(n)&&function(e,t){const n=Kr(),{anchor:o,focus:l}=e,r=o.getNode(),s=l.getNode(),i=Bn(r),c=Bn(s),a=i?Ln(i):null,u=c?Ln(c):null,h=e.isBackward(),f=i&&c&&a&&u&&a.is(u),g=u&&(!a||a.isParentOf(u)),m=a&&(!u||u.isParentOf(a));if(g){const t=e.clone(),[n]=Dt$1(u,c,c),o=n[0][0].cell,l=n[n.length-1].at(-1).cell;t.focus.set(h?o.getKey():l.getKey(),h?0:l.getChildrenSize(),"element"),el(t);}else if(m){const t=e.clone(),[n]=Dt$1(a,i,i),o=n[0][0].cell,l=n[n.length-1].at(-1).cell;t.anchor.set(h?l.getKey():o.getKey(),h?l.getChildrenSize():0,"element"),el(t);}else if(f){const o=t.observers.get(a.getKey());o||at$1(335,a.getKey());const[l]=o;if(i.is(c)||(l.$setAnchorCellForSelection(Yn(l,i)),l.$setFocusCellForSelection(Yn(l,c),true)),"touch"===l.pointerType&&l.isSelecting&&e.isCollapsed()&&cr(n)&&n.isCollapsed()){const e=Bn(n.anchor.getNode());e&&!e.is(c)&&(l.$setAnchorCellForSelection(Yn(l,e)),l.$setFocusCellForSelection(Yn(l,c),true),l.pointerType=null);}}}(n,e);for(const[n,o]of e.$getTableNodesAndObservers())Nn(t,n,o);return  false}function Nn(e,t,n){const o=Lr(),l=Kr();o&&!o.is(l)&&(tn(o)||tn(l))&&n.tableSelection&&!n.tableSelection.is(l)&&(tn(o)&&o.tableKey===n.tableNodeKey?n.$updateTableTableSelection(o):!tn(o)&&tn(l)&&l.tableKey===n.tableNodeKey&&n.$updateTableTableSelection(null)),n.hasHijackedSelectionStyles&&!t.isSelected()?function(e,t){t.$enableHighlightStyle(),On(t.table,t=>{const n=t.elem;t.highlighted=false,Hn(e,t),n.getAttribute("style")||n.removeAttribute("style");});}(e,n):!n.hasHijackedSelectionStyles&&t.isSelected()&&function(e,t){t.$disableHighlightStyle(),On(t.table,t=>{t.highlighted=true,zn(e,t);});}(e,n);}function xn(e){return e[an]||null}function vn(e){let t=e;for(;null!=t;){const e=t.nodeName;if("TD"===e||"TH"===e){const e=t._cell;return void 0===e?null:e}t=t.parentNode;}return null}function Tn(e,t){if(!e.contains(t))return null;let n=null;for(let o=t;null!=o;o=o.parentNode){if(o===e)return n;const t=o.nodeName;"TD"!==t&&"TH"!==t||(n=o._cell||null);}return null}function Rn(e,t){const n=[],o={columns:0,domRows:n,rows:0};let l=gn(e,t).querySelector("tr"),r=0,s=0;for(n.length=0;null!=l;){const e=l.nodeName;if("TD"===e||"TH"===e){const e={elem:l,hasBackgroundColor:""!==l.style.backgroundColor,highlighted:false,x:r,y:s};l._cell=e;let t=n[s];void 0===t&&(t=n[s]=[]),t[r]=e;}else {const e=l.firstChild;if(null!=e){l=e;continue}}const t=l.nextSibling;if(null!=t){r++,l=t;continue}const o=l.parentNode;if(null!=o){const e=o.nextSibling;if(null==e)break;s++,r=0,l=e;}}return o.columns=r+1,o.rows=s+1,o}function Fn(e,t,n){const o=new Set(n?n.getNodes():[]);On(t,(t,n)=>{const l=t.elem;o.has(n)?(t.highlighted=true,zn(e,t)):(t.highlighted=false,Hn(e,t),l.getAttribute("style")||l.removeAttribute("style"));});}function On(e,t){const{domRows:n}=e;for(let e=0;e<n.length;e++){const o=n[e];if(o)for(let n=0;n<o.length;n++){const l=o[n];if(!l)continue;const r=Xs(l.elem);null!==r&&t(l,r,{x:n,y:e});}}}const An=(e,t,n,o,l)=>{const r="forward"===l;switch(l){case "backward":case "forward":return n!==(r?e.table.columns-1:0)?Wn(t.getCellNodeFromCordsOrThrow(n+(r?1:-1),o,e.table),r):o!==(r?e.table.rows-1:0)?Wn(t.getCellNodeFromCordsOrThrow(r?0:e.table.columns-1,o+(r?1:-1),e.table),r):r?t.selectNext():t.selectPrevious(),true;case "up":return 0!==o?Wn(t.getCellNodeFromCordsOrThrow(n,o-1,e.table),false):t.selectPrevious(),true;case "down":return o!==e.table.rows-1?Wn(t.getCellNodeFromCordsOrThrow(n,o+1,e.table),true):t.selectNext(),true;default:return  false}};function Kn(e,t){let n,o;if(t.startColumn===e.minColumn)n="minColumn";else {if(t.startColumn+t.cell.__colSpan-1!==e.maxColumn)return null;n="maxColumn";}if(t.startRow===e.minRow)o="minRow";else {if(t.startRow+t.cell.__rowSpan-1!==e.maxRow)return null;o="maxRow";}return [n,o]}function kn([e,t]){return ["minColumn"===e?"maxColumn":"minColumn","minRow"===t?"maxRow":"minRow"]}function Mn(e,t,[n,o]){const l=t[o],r=e[l];void 0===r&&at$1(250,o,String(l));const s=t[n],i=r[s];return void 0===i&&at$1(250,n,String(s)),i}function En(e,t,n,o,l){const r=Xt$1(t,n,o),s=function(e,t){const{minColumn:n,maxColumn:o,minRow:l,maxRow:r}=t;let s=1,i=1,c=1,a=1;const u=e[l],h=e[r];for(let e=n;e<=o;e++)s=Math.max(s,u[e].cell.__rowSpan),a=Math.max(a,h[e].cell.__rowSpan);for(let t=l;t<=r;t++)i=Math.max(i,e[t][n].cell.__colSpan),c=Math.max(c,e[t][o].cell.__colSpan);return {bottomSpan:a,leftSpan:i,rightSpan:c,topSpan:s}}(t,r),{topSpan:i,leftSpan:c,bottomSpan:a,rightSpan:u}=s,h=function(e,t){const n=Kn(e,t);return null===n&&at$1(249,t.cell.getKey()),n}(r,n),[d,f]=kn(h);let g=r[d],m=r[f];"forward"===l?g+="maxColumn"===d?1:c:"backward"===l?g-="minColumn"===d?1:u:"down"===l?m+="maxRow"===f?1:i:"up"===l&&(m-="minRow"===f?1:a);const p=t[m];if(void 0===p)return  false;const C=p[g];if(void 0===C)return  false;const[_,S]=function(e,t,n){const o=Xt$1(e,t,n),l=Kn(o,t);if(l)return [Mn(e,o,l),Mn(e,o,kn(l))];const r=Kn(o,n);if(r)return [Mn(e,o,kn(r)),Mn(e,o,r)];const s=["minColumn","minRow"];return [Mn(e,o,s),Mn(e,o,kn(s))]}(t,n,C),b=Yn(e,_.cell),w=Yn(e,S.cell);return e.$setAnchorCellForSelection(b),e.$setFocusCellForSelection(w,true),true}function $n(e,t){if(cr(e)||tn(e)){const n=t.isParentOf(e.anchor.getNode()),o=t.isParentOf(e.focus.getNode());return n&&o}return  false}function Wn(e,t){t?e.selectStart():e.selectEnd();}function zn(e,t){const o=t.elem,l=e._config.theme;it$1(Xs(o))||at$1(131),vu(o,l.tableCellSelected);}function Hn(e,t){const n=t.elem;it$1(Xs(n))||at$1(131);const o=e._config.theme;Tu(n,o.tableCellSelected);}function Bn(e){const t=Jc(e,it$1);return it$1(t)?t:null}function Ln(e){const t=Jc(e,oo);return oo(t)?t:null}function Pn(e,t,n,o,l,r,s){const i=Xa(n.focus,l?"previous":"next");if(lu(i))return  false;let c=i;for(const e of Ua(i).iterNodeCarets("shadowRoot")){if(!wa(e)||!Pi(e.origin))return  false;c=e;}const a=c.getParentAtCaret();if(!it$1(a))return  false;const u=a,h=function(e){for(const t of Ua(e).iterNodeCarets("root")){const{origin:n}=t;if(it$1(n)){if(Ea(t))return La(n,e.direction)}else if(!ft$1(n))break}return null}(Da(u,c.direction)),d=Jc(u,oo);if(!d||!d.is(r))return  false;const g=e.getElementByKey(u.getKey()),m=vn(g);if(!g||!m)return  false;const p=eo(e,d);if(s.table=p,h)if("extend"===o){const t=vn(e.getElementByKey(h.origin.getKey()));if(!t)return  false;s.$setAnchorCellForSelection(m),s.$setFocusCellForSelection(t,true);}else {const e=su(h);Qa(n.anchor,e),Qa(n.focus,e);}else if("extend"===o)s.$setAnchorCellForSelection(m),s.$setFocusCellForSelection(m,true);else {const e=function(e){const t=za(e);return Ea(t)?su(t):e}(Da(d,i.direction));Qa(n.anchor,e),Qa(n.focus,e);}return In(t),true}function Dn(e,t,n,o,l,s){if(("up"===n||"down"===n)&&function(e){const t=e.getRootElement();if(!t)return  false;return t.hasAttribute("aria-controls")&&"typeahead-menu"===t.getAttribute("aria-controls")}(e))return  false;const i=Lr();if(!$n(i,o)){if(cr(i)){if("backward"===n){if(i.focus.offset>0)return  false;const e=function(e){for(let t=e,n=e;null!==n;t=n,n=n.getParent())if(Pi(n)){if(n!==t&&n.getFirstChild()!==t)return null;if(!n.isInline())return n}return null}(i.focus.getNode());if(!e)return  false;const n=e.getPreviousSibling();return !!oo(n)&&(In(t),t.shiftKey?i.focus.set(n.getParentOrThrow().getKey(),n.getIndexWithinParent(),"element"):n.selectEnd(),true)}if(t.shiftKey&&("up"===n||"down"===n)){const e=i.focus.getNode();if(!i.isCollapsed()&&("up"===n&&!i.isBackward()||"down"===n&&i.isBackward())){let l=Jc(e,e=>oo(e));if(it$1(l)&&(l=Jc(l,oo)),l!==o)return  false;if(!l)return  false;const s="down"===n?l.getNextSibling():l.getPreviousSibling();if(!s)return  false;let c=0;"up"===n&&Pi(s)&&(c=s.getChildrenSize());let a=s;if("up"===n&&Pi(s)){const e=s.getLastChild();a=e||s,c=Xo(a)?a.getTextContentSize():0;}const u=i.clone();return u.focus.set(a.getKey(),c,Xo(a)?"text":"element"),el(u),In(t),true}if(Kl(e)){const e="up"===n?i.getNodes()[i.getNodes().length-1]:i.getNodes()[0];if(e){if(null!==pn(o,e)){const e=o.getFirstDescendant(),t=o.getLastDescendant();if(!e||!t)return  false;const[n]=Ut$1(e),[r]=Ut$1(t),s=o.getCordsFromCellNode(n,l.table),i=o.getCordsFromCellNode(r,l.table),c=o.getDOMCellFromCordsOrThrow(s.x,s.y,l.table),a=o.getDOMCellFromCordsOrThrow(i.x,i.y,l.table);return l.$setAnchorCellForSelection(c),l.$setFocusCellForSelection(a,true),true}}return  false}{let o=Jc(e,e=>Pi(e)&&!e.isInline());if(it$1(o)&&(o=Jc(o,oo)),!o)return  false;const r="down"===n?o.getNextSibling():o.getPreviousSibling();if(oo(r)&&l.tableNodeKey===r.getKey()){const e=r.getFirstDescendant(),o=r.getLastDescendant();if(!e||!o)return  false;const[l]=Ut$1(e),[s]=Ut$1(o),c=i.clone();return c.focus.set(("up"===n?l:s).getKey(),"up"===n?0:s.getChildrenSize(),"element"),In(t),el(c),true}}}}return "down"===n&&Gn(e)&&s.setShouldCheckSelectionForTable(o.getKey()),false}if(cr(i)){if("backward"===n||"forward"===n){return Pn(e,t,i,t.shiftKey?"extend":"move","backward"===n,o,l)}if(i.isCollapsed()){const{anchor:r,focus:c}=i,a=Jc(r.getNode(),it$1),u=Jc(c.getNode(),it$1);if(!it$1(a)||!a.is(u))return  false;const h=Ln(a);if(h!==o&&null!=h){const o=gn(h,e.getElementByKey(h.getKey()));if(null!=o)return l.table=Rn(h,o),Dn(e,t,n,h,l,s)}const d=e.getElementByKey(a.__key),g=e.getElementByKey(r.key);if(null==g||null==d)return  false;let m;if("element"===r.type)m=g.getBoundingClientRect();else {const t=Hl(mn(e));if(null===t||0===t.rangeCount)return  false;const n=Zl(t,e.getRootElement());if(null===n)return  false;m=n.getBoundingClientRect();}const p="up"===n?a.getFirstChild():a.getLastChild();if(null==p)return  false;const C=e.getElementByKey(p.__key);if(null==C)return  false;const _=C.getBoundingClientRect();if("up"===n?_.top>m.top-m.height:m.bottom+m.height>_.bottom){In(t);const e=o.getCordsFromCellNode(a,l.table);if(!t.shiftKey)return An(l,o,e.x,e.y,n);{const t=o.getDOMCellFromCordsOrThrow(e.x,e.y,l.table);l.$setAnchorCellForSelection(t),l.$setFocusCellForSelection(t,true);}return  true}}}else if(tn(i)){const{anchor:r,focus:s,tableKey:c}=i;if(c!==o.getKey())return  false;const a=Jc(r.getNode(),it$1),u=Jc(s.getNode(),it$1),[h]=i.getNodes();oo(h)||at$1(251);const d=gn(h,e.getElementByKey(h.getKey()));if(!it$1(a)||!it$1(u)||!oo(h)||null==d)return  false;l.$updateTableTableSelection(i);const g=Rn(h,d),m=o.getCordsFromCellNode(a,g),p=o.getDOMCellFromCordsOrThrow(m.x,m.y,g);if(l.$setAnchorCellForSelection(p),In(t),t.shiftKey){const[e,t,r]=Dt$1(o,a,u);return En(l,e,t,r,n)}return u.selectEnd(),true}return  false}function In(e){e.preventDefault(),e.stopImmediatePropagation(),e.stopPropagation();}function Un(e,t,n){const o=ts();"first"===e?t.insertBefore(o):t.insertAfter(o),o.append(...n||[]),o.selectEnd();}function Xn(e,t,n){const o=n.getParent();if(!o)return;const l=Hl(mn(e));if(!l)return;const r=tc(l,e.getRootElement()).anchorNode,s=e.getElementByKey(o.getKey()),i=gn(n,e.getElementByKey(n.getKey()));if(!r||!s||!i||!s.contains(r)||i.contains(r))return;const c=Jc(t.anchor.getNode(),e=>it$1(e));if(!c)return;const a=Jc(c,e=>oo(e));if(!oo(a)||!a.is(n))return;const[u,h]=Dt$1(n,c,c),d=u[0][0],g=u[u.length-1][u[0].length-1],{startRow:m,startColumn:p}=h,C=m===d.startRow&&p===d.startColumn,_=m===g.startRow&&p===g.startColumn;return C?"first":_?"last":void 0}function Yn(e,t){const{tableNode:n}=e.$lookup(),o=n.getCordsFromCellNode(t,e.table);return n.getDOMCellFromCordsOrThrow(o.x,o.y,e.table)}function Jn(e,t,n){return pn(e,Xs(t,n))}function qn(e,n,o){const l=e.querySelector("colgroup");if(!l)return;const r=[];for(let e=0;e<n;e++){const n=Xl().createElement("col"),l=o&&o[e];l&&(n.style.width=`${l}px`),r.push(n);}l.replaceChildren(...r);}function jn(e,t,o){if(!t.theme.tableAlignment)return;const l=[],r=[];for(const e of ["center","right"]){const n=t.theme.tableAlignment[e];n&&(e===o?r:l).push(n);}Tu(e,...l),vu(e,...r);}const Vn=new WeakSet;function Gn(e=Cc()){return Vn.has(e)}class Zn extends Di{__rowStriping;__frozenColumnCount;__frozenRowCount;__colWidths;static getType(){return "table"}getColWidths(){return this.getLatest().__colWidths}setColWidths(e){const t=this.getWritable();return t.__colWidths=e,t}static clone(e){return new Zn(e.__key)}afterCloneFrom(e){super.afterCloneFrom(e),this.__colWidths=e.__colWidths,this.__rowStriping=e.__rowStriping,this.__frozenColumnCount=e.__frozenColumnCount,this.__frozenRowCount=e.__frozenRowCount;}static importDOM(){return {table:e=>({conversion:to,priority:1})}}static importJSON(e){return no().updateFromJSON(e)}updateFromJSON(e){return super.updateFromJSON(e).setRowStriping(e.rowStriping||false).setFrozenColumns(e.frozenColumnCount||0).setFrozenRows(e.frozenRowCount||0).setColWidths(e.colWidths)}constructor(e){super(e),this.__rowStriping=false,this.__frozenColumnCount=0,this.__frozenRowCount=0,this.__colWidths=void 0;}exportJSON(){return {...super.exportJSON(),colWidths:this.getColWidths(),frozenColumnCount:this.__frozenColumnCount?this.__frozenColumnCount:void 0,frozenRowCount:this.__frozenRowCount?this.__frozenRowCount:void 0,rowStriping:this.__rowStriping?this.__rowStriping:void 0}}extractWithChild(e,t,n){return "html"===n}getDOMSlot(e){const t=fn(e)?e:e.querySelector("table");return fn(t)||at$1(229),super.getDOMSlot(e).withElement(t).withAfter(t.querySelector("colgroup"))}createDOM(e,o){const l=Xl().createElement("table");this.__style&&Po(l.style,this.__style);const r=Xl().createElement("colgroup");if(l.appendChild(r),Ic(r),vu(l,e.theme.table),this.updateTableElement(null,l,e),Gn(o)){const o=Xl().createElement("div"),r=e.theme.tableScrollableWrapper;return r?vu(o,r):o.style.overflowX="auto",o.appendChild(l),this.updateTableWrapper(null,o,l,e),o}return l}updateTableWrapper(e,t,o,l){this.__frozenColumnCount!==(e?e.__frozenColumnCount:0)&&function(e,t,o,l){l>0?(vu(e,o.theme.tableFrozenColumn),t.setAttribute("data-lexical-frozen-column","true")):(Tu(e,o.theme.tableFrozenColumn),t.removeAttribute("data-lexical-frozen-column"));}(t,o,l,this.__frozenColumnCount),this.__frozenRowCount!==(e?e.__frozenRowCount:0)&&function(e,t,o,l){l>0?(vu(e,o.theme.tableFrozenRow),t.setAttribute("data-lexical-frozen-row","true")):(Tu(e,o.theme.tableFrozenRow),t.removeAttribute("data-lexical-frozen-row"));}(t,o,l,this.__frozenRowCount);}updateTableElement(e,t,o){this.__style!==(e?e.__style:"")&&Po(t.style,this.__style,e?e.__style:""),this.__rowStriping!==(!!e&&e.__rowStriping)&&function(e,t,o){o?(vu(e,t.theme.tableRowStriping),e.setAttribute("data-lexical-row-striping","true")):(Tu(e,t.theme.tableRowStriping),e.removeAttribute("data-lexical-row-striping"));}(t,o,this.__rowStriping);const l=e?e.getColumnCount():0,r=e?e.__colWidths:void 0;this.getColumnCount()===l&&this.getColWidths()===r||qn(t,this.getColumnCount(),this.getColWidths()),jn(t,o,this.getFormatType());}updateDOM(e,t,n){const l=gn(this,t);return t===l===Gn()||(dc(r=t)&&"DIV"===r.nodeName&&this.updateTableWrapper(e,t,l,n),this.updateTableElement(e,l,n),false);var r;}scaleDOMColWidths(e,t){const n=this.getColWidths();if(!n)return;qn(gn(this,e),this.getColumnCount(),n.map(e=>e*t));}exportDOM(e){const n=super.exportDOM(e),{element:l}=n;return {after:l=>{if(n.after&&(l=n.after(l)),!fn(l)&&dc(l)&&(l=l.querySelector("table")),!fn(l))return null;jn(l,e._config,this.getFormatType());const[r]=It$1(this,null,null),s=new Map;for(const e of r)for(const t of e){const e=t.cell.getKey();s.has(e)||s.set(e,{colSpan:t.cell.getColSpan(),startColumn:t.startColumn});}const i=new Set;for(const e of l.querySelectorAll(":scope > tr > [data-temporary-table-cell-lexical-key]")){const t=e.getAttribute("data-temporary-table-cell-lexical-key");if(t){const n=s.get(t);if(e.removeAttribute("data-temporary-table-cell-lexical-key"),n){s.delete(t);for(let e=0;e<n.colSpan;e++)i.add(e+n.startColumn);}}}const c=l.querySelector(":scope > colgroup");if(c){const e=Array.from(l.querySelectorAll(":scope > colgroup > col")).filter((e,t)=>i.has(t));c.replaceChildren(...e);}const a=l.querySelectorAll(":scope > tr");if(a.length>0){const e=Xl().createElement("tbody");for(const t of a)e.appendChild(t);l.append(e);}return l},element:!fn(l)&&dc(l)?l.querySelector("table"):l}}canBeEmpty(){return  false}isShadowRoot(){return  true}getCordsFromCellNode(e,t){const{rows:n,domRows:o}=t;for(let t=0;t<n;t++){const n=o[t];if(null!=n)for(let o=0;o<n.length;o++){const l=n[o];if(null==l)continue;const{elem:r}=l,s=Jn(this,r);if(null!==s&&e.is(s))return {x:o,y:t}}}throw new Error("Cell not found in table.")}getDOMCellFromCords(e,t,n){const{domRows:o}=n,l=o[t];if(null==l)return null;const r=l[e<l.length?e:l.length-1];return null==r?null:r}getDOMCellFromCordsOrThrow(e,t,n){const o=this.getDOMCellFromCords(e,t,n);if(!o)throw new Error("Cell not found at cords.");return o}getCellNodeFromCords(e,t,n){const o=this.getDOMCellFromCords(e,t,n);if(null==o)return null;const l=Xs(o.elem);return it$1(l)?l:null}getCellNodeFromCordsOrThrow(e,t,n){const o=this.getCellNodeFromCords(e,t,n);if(!o)throw new Error("Node at cords not TableCellNode.");return o}getRowStriping(){return Boolean(this.getLatest().__rowStriping)}setRowStriping(e){const t=this.getWritable();return t.__rowStriping=e,t}setFrozenColumns(e){const t=this.getWritable();return t.__frozenColumnCount=e,t}getFrozenColumns(){return this.getLatest().__frozenColumnCount}setFrozenRows(e){const t=this.getWritable();return t.__frozenRowCount=e,t}getFrozenRows(){return this.getLatest().__frozenRowCount}canSelectBefore(){return  true}canIndent(){return  false}getColumnCount(){const e=this.getFirstChild();if(!ft$1(e))return 0;let t=0;return e.getChildren().forEach(e=>{it$1(e)&&(t+=e.getColSpan());}),t}}function eo(e,t){const n=e.getElementByKey(t.getKey());return null===n&&at$1(230),Rn(t,n)}function to(e){const t=no();e.hasAttribute("data-lexical-row-striping")&&t.setRowStriping(true),e.hasAttribute("data-lexical-frozen-column")&&t.setFrozenColumns(1),e.hasAttribute("data-lexical-frozen-row")&&t.setFrozenRows(1);const n=e.querySelector(":scope > colgroup");if(n){let e=[];for(const t of n.querySelectorAll(":scope > col")){let n=t.style.width||"";if(!tt$1.test(n)&&(n=t.getAttribute("width")||"",!/^\d+$/.test(n))){e=void 0;break}e.push(parseFloat(n));}e&&t.setColWidths(e);}return {after:e=>Wt$3(e,ft$1),node:t}}function no(){return Bl(new Zn)}function oo(e){return e instanceof Zn}function lo(e){ft$1(e.getParent())?e.isEmpty()&&e.append(ts()):e.remove();}function ro(e){oo(e.getParent())?zt$5(e,it$1):e.remove();}function so(e){zt$5(e,ft$1);const[t]=It$1(e,null,null),n=t.reduce((e,t)=>Math.max(e,t.length),0),o=e.getChildren();for(let e=0;e<t.length;++e){const l=o[e];if(!l)continue;ft$1(l)||at$1(254,l.constructor.name,l.getType());const r=t[e].reduce((e,t)=>t?1+e:e,0);if(r!==n)for(let e=r;e<n;++e){const e=st$1();e.append(ts()),l.append(e);}}const l=e.getColWidths(),r=e.getColumnCount();if(l&&l.length!==r){let t;if(r<l.length)t=l.slice(0,r);else if(l.length>0){const e=l[l.length-1];t=[...l,...Array(r-l.length).fill(e)];}e.setColWidths(t);}}function io(e){if(e.detail<3||!hc(e.target))return  false;const t=Xs(e.target);if(null===t)return  false;const n=Jc(t,e=>Pi(e)&&!e.isInline());if(null===n)return  false;return !!it$1(n.getParent())&&(n.select(0),true)}function co(){const e=Lr();if(!cr(e))return  false;const t=Ln(e.anchor.getNode());if(null===t)return  false;const n=tl();if(!n.is(t.getParent())||1!==n.getChildrenSize())return  false;const[o]=It$1(t,null,null);if(0===o.length||0===o[0].length)return  false;const l=o[0][0];if(!l||!l.cell)return  false;const r=o[o.length-1],s=r[r.length-1];if(!s||!s.cell)return  false;const i=on(t,l.cell,s.cell);return el(i),true}function uo(e,t=true){const n=new sn,o=(o,l,r)=>{const s=gn(o,r),i=wn(o,s,e,t,n);n.observers.set(l,[i,s]);};return ku(bn(e,n),e.registerCommand(Ie$2,()=>yn(n,e),ss),e.registerMutationListener(Zn,t=>{e.read("latest",()=>{for(const[e,l]of t){const t=n.observers.get(e);if("created"===l||"updated"===l){const{tableNode:l,tableElement:r}=rn(e);void 0===t?o(l,e,r):r!==t[1]&&(n.removeObserver(e),o(l,e,r));}else "destroyed"===l&&n.removeObserver(e);}});},{skipInitialization:false}),()=>{n.removeAllObservers();})}function ho(e,t){e.hasNodes([Zn])||at$1(255);const{hasNestedTables:n=Yt$1(false)}=t??{};return ku(e.registerCommand(ct$1,e=>function({rows:e,columns:t,includeHeaders:n},o){const l=Lr()||Kr();if(!l||!cr(l))return  false;if(!o&&Ln(l.anchor.getNode()))return  false;const s=gt$1(Number(e),Number(t),n);Bt$3(s);const i=s.getFirstDescendant();return Xo(i)&&i.select(),true}(e,n.peek()),os),e.registerCommand(Le$3,(t,o)=>e===o&&function(e,t){const{nodes:n,selection:o}=e;if(!n.some(e=>oo(e)||dt$6(e).some(e=>oo(e.node)))){if(tn(o)){let e="",t=false;for(const o of n){const n=Pi(o)&&!o.isInline();e.length>0&&(n||t)&&(e+="\n"),e+=o.getTextContent(),t=n;}return o.insertRawText(e),true}return  false}const l=tn(o),r=cr(o);if(!(r&&null!==Jc(o.anchor.getNode(),e=>it$1(e))&&null!==Jc(o.focus.getNode(),e=>it$1(e))||l))return  false;if(1===n.length&&oo(n[0]))return Vt$1(n[0],o);if(r&&t.peek()&&!function(e){if(tn(e)&&!e.focus.getNode().is(e.anchor.getNode()))return  true;if(cr(e)&&it$1(e.anchor.getNode())&&!e.anchor.getNode().is(e.focus.getNode()))return  true;return  false}(o))return  false;return  true}(t,n),os),e.registerCommand(kn$2,co,rs),e.registerCommand(Ke$3,io,os),e.registerNodeTransform(Zn,so),e.registerNodeTransform(ut$1,ro),e.registerNodeTransform(ot$1,lo))}[/* @__PURE__ */Se$1({$import:(e,t)=>{const n=no();t.hasAttribute("data-lexical-row-striping")&&n.setRowStriping(true),t.hasAttribute("data-lexical-frozen-column")&&n.setFrozenColumns(1),t.hasAttribute("data-lexical-frozen-row")&&n.setFrozenRows(1);const o=t.querySelector(":scope > colgroup");if(o){let e=[];for(const t of o.querySelectorAll(":scope > col")){let n=t.style.width||"";if(!tt$1.test(n)&&(n=t.getAttribute("width")||"",!/^\d+$/.test(n))){e=void 0;break}e.push(parseFloat(n));}e&&n.setColWidths(e);}return [n.splice(0,0,Wt$3(e.$importChildren(t),ft$1))]},match:Cn$1.tag("table"),name:"@lexical/table/table"}),/* @__PURE__ */Se$1({$import:(e,t)=>[dt$1(tt$1.test(t.style.height)?parseFloat(t.style.height):void 0).splice(0,0,Wt$3(e.$importChildren(t),it$1))],match:Cn$1.tag("tr"),name:"@lexical/table/tr"}),/* @__PURE__ */Se$1({$import:(e,t)=>{const n="TH"===t.nodeName,o=tt$1.test(t.style.width)?parseFloat(t.style.width):void 0;let c=nt$1.NO_STATUS;if(n){const e=t.getAttribute("scope");if("col"===e)c=nt$1.COLUMN;else if("row"===e)c=nt$1.ROW;else {const e=t.parentElement,n=uc(e)&&(e.parentElement&&"THEAD"===e.parentElement.nodeName||0===e.rowIndex),o=0===t.cellIndex;n&&(c|=nt$1.ROW),o&&(c|=nt$1.COLUMN),c===nt$1.NO_STATUS&&(c=nt$1.ROW);}}const a=st$1(c,t.colSpan,o);a.__rowSpan=t.rowSpan;const u=t.style.backgroundColor;""!==u&&(a.__backgroundColor=u);const h=t.style.verticalAlign;(function(e){return "middle"===e||"bottom"===e})(h)&&(a.__verticalAlign=h);const d=e.get(be),f=d|function(e){let t=0;const n=e.fontWeight;"700"!==n&&"bold"!==n||(t|=p),"italic"===e.fontStyle&&(t|=y);const o=(e.textDecoration||"").split(" ");return o.includes("underline")&&(t|=x),o.includes("line-through")&&(t|=m),t}(t.style),g=e.get(ke$1),m$1=t.style.color,p$1=m$1?{...g,color:m$1}:g,C=[];f!==d&&C.push(ft$3(be,f)),p$1!==g&&C.push(ft$3(ke$1,p$1));const _=function(e){const t=[];let n=null;const o=()=>{if(null!==n){const e=n.getFirstChild();qi(e)&&1===n.getChildrenSize()&&e.remove();}};for(const c of e)Fl(c)||Xo(c)||qi(c)?null!==n?n.append(c):(n=ts().append(c),t.push(n)):(o(),n=null,t.push(c));return o(),0===t.length&&t.push(ts()),t}(e.$importChildren(t,{context:C})),S=n?_:Fe$1(_,t);return [a.splice(0,0,S)]},match:Cn$1.tag("td","th"),name:"@lexical/table/cell"})];

	/**
	 * Copyright (c) Meta Platforms, Inc. and affiliates.
	 *
	 * This source code is licensed under the MIT license found in the
	 * LICENSE file in the root directory of this source tree.
	 *
	 */

	function Q(t,e){const n={};for(const o of t){const t=e(o);t&&(n[t]?n[t].push(o):n[t]=[o]);}return n}function V(t){const e=Q(t,t=>t.type);return {element:e.element||[],multilineElement:e["multiline-element"]||[],textFormat:e["text-format"]||[],textMatch:e["text-match"]||[]}}const X=/[!-/:-@[-`{-~\s]/,Y=/\s/,Z=/[!"#$%&'()*+,\-./:;<=>?@[\]^_`{|}~]/,tt=/^\s{0,3}$/;function et(n){if(!es(n))return  false;const o=n.getFirstChild();return null==o||1===n.getChildrenSize()&&Xo(o)&&tt.test(o.getTextContent())}function nt(t){return t.replace(/\\([!-/:-@[-`{-~])/g,"$1").replace(/&#(\d+);/g,(t,e)=>String.fromCodePoint(Number(e)))}const ot=/^(\s*)(\d{1,})\.\s/,rt=/^(\s*)[-*+]\s/,st=/^(\s*)(?:[-*+]\s)?\s?(\[(\s|x)?\])\s/i,it=/^(#{1,6})\s/,lt=/^>\s/,ct=/^([ \t]*`{3,})([\w-]+)?[ \t]?/,ft=/^[ \t]*`{3,}$/,at=/^[ \t]*```[^`]+(?:(?:`{1,2}|`{4,})[^`]+)*```(?:[^`]|$)/,ut=/^(?:\|)(.+)(?:\|)\s?$/;function dt(t){if("|"!==t[0])return  false;const{length:e}=t;let n=1,o=0;for(;n<e;){let e=n;for(" "===t[e]&&e++,":"===t[e]&&e++;"-"===t[e];)e++;if(":"===t[e]&&e++," "===t[e]&&e++,"|"!==t[e])break;o++,n=e+1;}return o>0&&(n===e||n===e-1&&/\s/.test(t[n]))}const gt=/^<[a-z_][\w-]*(?:\s[^<>]*)?\/?>/i,ht=/^<\/[a-z_][\w-]*\s*>/i,pt=t=>new RegExp(`(?:${t.source})$`,t.flags),xt=/* @__PURE__ */mt$3("mdListMarker",{parse:t=>"string"==typeof t&&/^[-*+]$/.test(t)?t:"-",resetOnCopyNode:true}),mt=/* @__PURE__ */mt$3("mdCodeFence",{parse:t=>"string"==typeof t&&/^`{3,}$/.test(t)?t:"```",resetOnCopyNode:true}),Ct=/* @__PURE__ */mt$3("mdHardLineBreak",{parse:t=>"string"==typeof t&&/^(\\| {2,})$/.test(t)?t:"",resetOnCopyNode:true});function It(t){if(t.endsWith("\\"))return [t.slice(0,-1),"\\"];const e=t.match(/^(.*?\S)( {2,})$/);return e?[e[1],e[2]]:null}function Tt(t){const n=t.getChildren(),o=n.length-1,r=n[o];if(!Xo(r))return null;const s=r.getTextContent(),i=It(s);if(null!==i){const[t,e]=i;return r.setTextContent(t),e}return /^ {2,}$/.test(s)&&function(t,e){for(let n=e-1;n>=0;n--){if(qi(t[n]))return  false;if(/\S/.test(t[n].getTextContent()))return  true}return  false}(n,o)?(r.setTextContent(""),s):null}function $t(t){const e=Vi(),o=Tt(t);return null!==o&&St$4(e,Ct,o),e}const Et=t=>(e,n,o,r)=>{const s=t(o);s.append(...n),e.replace(s),r||s.select(0,0);};const vt=t=>(e,o,r,s)=>{const i=e.getPreviousSibling(),l=e.getNextSibling(),c=Ot$1("check"===t?"x"===r[3]:void 0),f=r[0].trim()[0],a="bullet"!==t&&"check"!==t||f!==xt.parse(f)?void 0:f;if(Rt$1(l)&&l.getListType()===t){a&&St$4(l,xt,a);const o=l.getFirstChild();null!==o?o.insertBefore(c):l.append(c),"number"===t&&l.setStart(Number(r[2])),e.remove();}else if(Rt$1(i)&&i.getListType()===t)a&&St$4(i,xt,a),i.append(c),e.remove();else {const o=$t$1(t,"number"===t?Number(r[2]):void 0);a&&St$4(o,xt,a),o.append(c),e.replace(o);}c.append(...o),s||c.select(0,0);const u=function(t){const e=t.match(/\t/g),n=t.match(/ /g);let o=0;return e&&(o+=e.length),n&&(o+=Math.floor(n.length/4)),o}(r[1]);u&&c.setIndent(u);},yt=(t,e,n,o)=>{const s=[],i=t.getChildren();let l=0;for(const c of i)if(It$2(c)){if(1===c.getChildrenSize()){const t=c.getFirstChild();if(Rt$1(t)){const r=yt(t,e,n+1,o);r&&s.push(r);continue}}if(o&&!c.getChildren().some(t=>t.isSelected(o)))continue;const i=" ".repeat(4*n),f=t.getListType(),a=xt$4(t,xt),u="number"===f?`${t.getStart()+l}. `:"check"===f?`${a} [${c.getChecked()?"x":" "}] `:a+" ";let d=e(c);"number"!==f&&(d=d.replace(/^(\s{0,3}\d+)(\.\s)/,"$1\\$2")),s.push(i+u+d),l++;}return s.join("\n")},St={dependencies:[_t$2],export:(t,e)=>{if(!Kt$1(t))return null;const n=Number(t.getTag().slice(1));return "#".repeat(n)+" "+e(t)},regExp:it,replace:Et(t=>{const e="h"+t[1].length;return kt$2(e)}),triggerOnEnter:true,type:"element"},bt={dependencies:[Nt$2],export:(t,e)=>{if(!bt$2(t))return null;const n=e(t).split("\n"),o=[];for(const t of n)o.push("> "+t);return o.join("\n")},regExp:lt,replace:(t,e,n,o)=>{if(o){const n=t.getPreviousSibling();if(bt$2(n))return n.splice(n.getChildrenSize(),0,[$t(n),...e]),void t.remove()}const r=St$2();r.append(...e),t.replace(r),o||r.select(0,0);},triggerOnEnter:true,type:"element"},wt={dependencies:[ve],export:t=>{if(!Ne(t))return null;const e=t.getTextContent();let n=xt$4(t,mt);if(e.indexOf(n)>-1){const t=e.match(/`{3,}/g);if(t){const e=Math.max(...t.map(t=>t.length));n="`".repeat(e+1);}}return n+(t.getLanguage()||"")+(e?"\n"+e:"")+"\n"+n},handleImportAfterStartMatch:({lines:t,rootNode:e,startLineIndex:n,startMatch:o})=>{const r=o[1],s=r.trim().length,i=t[n],l=o.index+r.length,c=i.slice(l),f=new RegExp(`\`{${s},}$`);if(f.test(c)){const t=c.match(f),r=c.slice(0,c.lastIndexOf(t[0])),s=[...o];return s[2]="",wt.replace(e,null,s,t,[r],true),[true,n]}const a=new RegExp(`^[ \\t]*\`{${s},}$`);for(let r=n+1;r<t.length;r++){const s=t[r];if(a.test(s)){const l=s.match(a),c=t.slice(n+1,r),f=i.slice(o[0].length);return f.length>0&&c.unshift(f),wt.replace(e,null,o,l,c,true),[true,r]}}const u=t.slice(n+1),d=i.slice(o[0].length);return d.length>0&&u.unshift(d),wt.replace(e,null,o,null,u,true),[true,t.length-1]},regExpEnd:{optional:true,regExp:ft},regExpStart:ct,replace:(t,e,r,s,i,l)=>{let c,f;const a=r[1]?r[1].trim():"```",u=r[2]||void 0;if(!e&&i){if(1===i.length)s?(c=Ce$1(u),f=i[0]):(c=Ce$1(u),f=i[0].startsWith(" ")?i[0].slice(1):i[0]);else {for(c=Ce$1(u),i.length>0&&(0===i[0].trim().length?i.shift():i[0].startsWith(" ")&&(i[0]=i[0].slice(1)));i.length>0&&!i[i.length-1].length;)i.pop();f=i.join("\n");}St$4(c,mt,a);const e=Go(f);c.append(e),t.append(c);}else e&&Et(t=>Ce$1(t?t[2]:void 0))(t,e,r,l);},type:"multiline-element"},kt={dependencies:[Et$1,Pt$2],export:(t,e,n)=>Rt$1(t)?yt(t,e,0,n):null,regExp:rt,replace:vt("bullet"),triggerOnEnter:true,type:"element"},Ft={dependencies:[Et$1,Pt$2],export:(t,e,n)=>Rt$1(t)?yt(t,e,0,n):null,regExp:ot,replace:vt("number"),triggerOnEnter:true,type:"element"},Ot={format:["code"],tag:"`",type:"text-format"};const Mt={format:["highlight"],tag:"==",type:"text-format"},_t={format:["bold","italic"],tag:"***",type:"text-format"},Bt={format:["bold","italic"],intraword:false,tag:"___",type:"text-format"},Lt={format:["bold"],tag:"**",type:"text-format"},Rt={format:["bold"],intraword:false,tag:"__",type:"text-format"},jt={format:["strikethrough"],tag:"~~",type:"text-format"},Pt={format:["italic"],tag:"*",type:"text-format"},At={format:["italic"],intraword:false,tag:"_",type:"text-format"},Wt={dependencies:[Z$1],export:(t,e,n)=>{if(!X$1(t)||et$1(t))return null;const o=e(t);let r=t.getTitle();null!=r&&(r=r.replace(/([\\"])/g,"\\$1"));return r?`[${o}](${t.getURL()} "${r}")`:`[${o}](${t.getURL()})`},importRegExp:/(?:\[(.+?)\])(?:\((?:([^()\s]+)(?:\s"((?:[^"]*\\")*[^"]*)"\s*)?)\))/,regExp:/(?:\[([^[\]]*(?:\[[^[\]]*\][^[\]]*)*)\])(?:\((?:([^()\s]+)(?:\s"((?:[^"]*\\")*[^"]*)"\s*)?)\))$/,replace:(t,e)=>{if(Jc(t,X$1))return;const[,n,r,i]=e,l=null!=r?nt(r):void 0,c=null!=i?nt(i):void 0,f=V$1(l,{title:c}),a=n.split("[").length-1,u=n.split("]").length-1;let d=n,g="";if(a<u)return;if(a>u){const t=n.split("[");g="["+t[0],d=t.slice(1).join("[");}const h=Go(d);return h.setFormat(t.getFormat()),f.append(h),t.replace(f),g&&f.insertBefore(Go(g)),h},trigger:")",type:"text-match"},zt=[St,bt,kt,Ft],Ut=[wt],Dt=[Ot,_t,Bt,Lt,Rt,Mt,Pt,At,jt],Ht=[Wt],Kt=[...zt,...Ut,...Dt,...Ht];function qt(t,e=false){const n=t.split("\n");let o=0;const r=[];for(let t=0;t<n.length;t++){const s=n[t],i=s.trimEnd(),l=r[r.length-1],c=t<n.length-1?It(s):null,f=void 0!==l&&null!==It(l);if(at.test(i))r.push(i);else if(0===o){{const t=i.match(ct);if(t){o=t[1].trim().length,r.push(i);continue}}""===i||""===l||!l||it.test(l)||it.test(i)||lt.test(i)||ot.test(i)||rt.test(i)||st.test(i)||ut.test(i)||dt(i)||f||!e||gt.test(i)||ht.test(i)||pt(ht).test(l)||pt(gt).test(l)||ft.test(l)?r.push(!e&&""!==i||null!==c?s:i):r[r.length-1]=l+" "+(null===c?i:s).trimStart();}else {if(ft.test(i)&&i.trim().length>=o){o=0,r.push(i);continue}r.push(s);}}return r.join("\n")}function Qt(t,e,n,o,r){for(const s of e){if(!s.export)continue;const e=s.export(t,t=>Vt(t,n,o,void 0,void 0,r));if(null!=e)return e}return Pi(t)?Vt(t,n,o,void 0,void 0,r):Li(t)?t.getTextContent():null}function Vt(t,n,o,r,s,i=false){const l=[],f=t.getChildren();r||(r=[]),s||(s=[]);t:for(const t of f){for(const e of o){if(!e.export)continue;const c=e.export(t,t=>Vt(t,n,o,r,[...s,...r],i),(t,e)=>Yt(t,e,n,r,s,i));if(null!=c){l.push(c);continue t}}qi(t)?l.push(Xt(t)):Xo(t)?l.push(Yt(t,t.getTextContent(),n,r,s,i)):Pi(t)?l.push(Vt(t,n,o,r,s,i)):Li(t)&&l.push(t.getTextContent());}return l.join("")}function Xt(t){return xt$4(t,Ct)+"\n"}function Yt(t,e,n,o,r,s=false){const i=t.hasFormat("code");let l,c,f,a,u=e;if(i||(u=s?u.replace(/([*_`~])/g,"\\$1"):u.replace(/([*_`~\\])/g,"\\$1")),i){const{fence:t,padded:n}=function(t){const e=t.match(/`+/g),n=e?Math.max(...e.map(t=>t.length)):0;return {fence:"`".repeat(n+1),padded:0===t.length||t.includes("`")||/^\s/.test(t)&&/\s$/.test(t)?` ${t} `:t}}(e);l="",f="",c=t+n+t,a=false;}else {const t=u.match(/^(\s*)(.*?)(\s*)$/s)||["","",u,""];l=t[1],c=t[2],f=t[3],a=""===c;}let d="",g="",h="";const p=Zt(t,true),x=Zt(t,false),m=new Set;for(const e of n){const n=e.format[0],r=e.tag;"code"!==n&&(ee(t,n)&&!m.has(n)&&(m.add(n),ee(p,n)&&o.find(t=>t.tag===r)||(o.push({format:n,tag:r}),d+=r)));}for(let e=0;e<o.length;e++){const n=te(t,o[e].format),s=te(x,o[e].format);if(n&&s)continue;const i=[...o];for(;i.length>e;){const t=i.pop();r&&t&&r.find(e=>e.tag===t.tag)||(t&&"string"==typeof t.tag&&(n?s||(h+=t.tag):g+=t.tag),o.pop());}break}return a&&!t.hasFormat("code")?g+u:g+l+d+c+h+f}function Zt(t,n){const o=n?t.getPreviousSibling():t.getNextSibling();return Xo(o)?o:null}function te(t,n){return Xo(t)&&t.hasFormat(n)}function ee(t,e){return !!te(t,e)&&("code"===e||(!t||!/^\s*$/.test(t.getTextContent())))}function ne(t,e){const n=t.getTextContent(),o=e.transformersByTag["`"],r=[];let s=null;if(o){const t=function(t){const e=e=>{let n=0;for(let o=e-1;o>=0&&"\\"===t[o];o--)n++;return n%2==1},n=[];let o=0;for(;o<t.length;)if("`"===t[o]){let e=1;for(;o+e<t.length&&"`"===t[o+e];)e++;n.push({index:o,length:e}),o+=e;}else o++;const r=[];let s=0;for(;s<n.length;){const o=n[s];if(e(o.index)){s++;continue}let i=-1;for(let t=s+1;t<n.length;t++)if(n[t].length===o.length){i=t;break}if(-1===i){s++;continue}const l=n[i];let c=t.slice(o.index+o.length,l.index);c.length>=2&&c.startsWith(" ")&&c.endsWith(" ")&&/[^ ]/.test(c)&&(c=c.slice(1,-1)),r.push({content:c,endIndex:l.index+l.length,startIndex:o.index}),s=i+1;}return r}(n);for(const e of t)s||(s={content:e.content,endIndex:e.endIndex,startIndex:e.startIndex,tag:"`"}),r.push({end:e.endIndex,start:e.startIndex});}const i=function(t,e,n=[]){const o=[],r=new Set(Object.keys(e.transformersByTag).filter(t=>"`"!==t[0]).map(t=>t[0])),s=e=>{let n=0;for(let o=e-1;o>=0&&"\\"===t[o];o--)n++;return n%2==1},i=t=>n.some(e=>t>=e.start&&t<e.end);let l=0;for(;l<t.length;){const e=t[l];if(!r.has(e)||s(l)||i(l)){l++;continue}let n=1;for(;l+n<t.length&&t[l+n]===e;)n++;const c=oe(e,t,l,n,true),f=oe(e,t,l,n,false);(c||f)&&o.push({active:true,canClose:f,canOpen:c,char:e,index:l,length:n}),l+=n;}return o}(n,e,r),l=i.length>0?function(t,e,n){const o={};let r=0,s=null;for(;r<e.length;){const i=e[r];if(!i.active||!i.canClose||0===i.length){r++;continue}const l=`${i.char}${i.canOpen}${i.length%3}`,c=o[l]??-1;let f=false;for(let o=r-1;o>c;o--){const l=e[o];if(!l.active||!l.canOpen||0===l.length||l.char!==i.char)continue;if(l.canClose||i.canOpen){if((l.length+i.length)%3==0&&l.length%3!=0&&i.length%3!=0)continue}const c=Math.min(l.length,i.length),a=Object.keys(n.transformersByTag).filter(t=>t[0]===l.char&&t.length<=c).sort((t,e)=>e.length-t.length)[0];if(!a)continue;f=true;const u=a.length,d={content:t.slice(l.index+l.length,i.index),endIndex:i.index+u,startIndex:l.index+(l.length-u),tag:a};(!s||d.startIndex<s.startIndex||d.startIndex===s.startIndex&&d.endIndex>s.endIndex)&&(s=d);for(let t=o+1;t<r;t++)e[t].active=false;l.length-=u,i.length-=u,l.active=l.length>0,i.length>0?i.index+=u:(i.active=false,r++);break}f||(o[l]=r-1,i.canOpen||(i.active=false),r++);}return s}(n,i,e):null;let c=null,f=null;if(s&&l?l.startIndex<=s.startIndex&&l.endIndex>=s.endIndex?(c=l,f=e.transformersByTag[l.tag]):(c=s,f=o):s?(c=s,f=o):l&&(c=l,f=e.transformersByTag[l.tag]),!c||!f)return null;const a=[n.slice(c.startIndex,c.endIndex),c.tag,c.content];return a.index=c.startIndex,a.input=n,{endIndex:c.endIndex,isCodeSpan:f===o,match:a,startIndex:c.startIndex,transformer:f}}function oe(t,e,n,o,r){if(!re(e,n,o,r))return  false;if("*"===t)return  true;if("_"===t){if(!re(e,n,o,!r))return  true;const t=r?e[n-1]:e[n+o];return void 0!==t&&Z.test(t)}return  true}function re(t,e,n,o){const r=t[e-1],s=t[e+n],[i,l]=o?[s,r]:[r,s];return void 0!==i&&!Y.test(i)&&(!Z.test(i)||(void 0===l||Y.test(l)||Z.test(l)))}function se(t){return Xo(t)&&!t.hasFormat("code")}function ie(t,e,n){let o=ne(t,e),r=function(t,e){const n=t;let o,r,s,i;for(const t of e){if(!t.replace||!t.importRegExp)continue;const e=n.getTextContent().match(t.importRegExp);if(!e)continue;const l=e.index||0,c=t.getEndIndex?t.getEndIndex(n,e):l+e[0].length;false!==c&&(void 0===o||void 0===r||l<o&&(c>r||c<=o))&&(o=l,r=c,s=t,i=e);}return void 0===o||void 0===r||void 0===s||void 0===i?null:{endIndex:r,match:i,startIndex:o,transformer:s}}(t,n);if(o&&r&&(o.isCodeSpan?r.startIndex<=o.startIndex&&r.endIndex>=o.endIndex?o=null:r=null:o.startIndex<=r.startIndex&&o.endIndex>=r.endIndex||r.startIndex>o.endIndex?r=null:o=null),o){const r=function(t,e,n,o,r){const s=t.getTextContent();let i,l,c;if(r[0]===s?i=t:0===e?[i,l]=t.splitText(n):[c,i,l]=t.splitText(e,n),i.setTextContent(r[2]),o)for(const t of o.format)i.hasFormat(t)||i.toggleFormat(t);return {nodeAfter:l,nodeBefore:c,transformedNode:i}}(t,o.startIndex,o.endIndex,o.transformer,o.match);se(r.nodeAfter)&&ie(r.nodeAfter,e,n),se(r.nodeBefore)&&ie(r.nodeBefore,e,n),se(r.transformedNode)&&ie(r.transformedNode,e,n);}else if(r){const o=function(t,e,n,o,r){let s,i,l;return 0===e?[s,i]=t.splitText(n):[l,s,i]=t.splitText(e,n),o.replace?{nodeAfter:i,nodeBefore:l,transformedNode:o.replace(s,r)||void 0}:null}(t,r.startIndex,r.endIndex,r.transformer,r.match);if(!o)return;se(o.nodeAfter)&&ie(o.nodeAfter,e,n),se(o.nodeBefore)&&ie(o.nodeBefore,e,n),se(o.transformedNode)&&ie(o.transformedNode,e,n);}const s=nt(t.getTextContent());t.setTextContent(s);}function le(t,e,n,o=false){const r=V(n),s=function(t){const e={},n={},o=[];for(const r of t){const{tag:t}=r;e[t]=r;const s=t.replace(/(\*|\^|\+)/g,"\\$1");o.push(s),1===t.length?n[t]="`"===t?new RegExp("(^|[^\\\\`])(`)((?:\\\\`|[^`])+?)(`)(?!`)"):new RegExp(`(^|[^\\\\${s}])(${s})((\\\\${s})?.*?[^${s}\\s](\\\\${s})?)(${s})(?![\\\\${s}])`):n[t]=new RegExp(`(^|[^\\\\])(${s})((\\\\${s})?.*?[^\\s](\\\\${s})?)(${s})(?!\\\\)`);}return {fullMatchRegExpByTag:n,openTagsRegExp:new RegExp(`(${o.join("|")})`,"g"),transformersByTag:e}}(r.textFormat),i=t.split("\n"),l=i.length;for(let t=0;t<l;t++){const n=i[t],[l,c]=ce(i,t,r.multilineElement,e);l?t=c:fe(n,e,r.element,s,r.textMatch,o);}const c=e.getChildren();for(const t of c)if(!o&&et(t)&&e.getChildrenSize()>1)t.remove();else if(Pi(t))for(const e of t.getAllTextNodes())ae(e);}function ce(t,e,n,o){for(const r of n){const{handleImportAfterStartMatch:n,regExpEnd:s,regExpStart:i,replace:l}=r,c=t[e].match(i);if(!c)continue;if(n){const s=n({lines:t,rootNode:o,startLineIndex:e,startMatch:c,transformer:r});if(null===s)continue;if(s)return s}const f="object"==typeof s&&"regExp"in s?s.regExp:s,a=s&&"object"==typeof s&&"optional"in s?s.optional:!s;let u=e;const d=t.length;for(;u<d;){const n=f?t[u].match(f):null;if(!n&&(!a||a&&u<d-1)){u++;continue}if(n&&e===u&&n.index===c.index){u++;continue}const r=[];if(n&&e===u)r.push(t[e].slice(c[0].length,-n[0].length));else for(let o=e;o<=u;o++)if(o===e){const e=t[o].slice(c[0].length);r.push(e);}else if(o===u&&n){const e=t[o].slice(0,-n[0].length);r.push(e);}else r.push(t[o]);if(false!==l(o,null,c,n,r,true))return [true,u];break}}return [false,e]}function fe(e,n,r,i,l,c){const f=Go(e),a=ts();a.append(f),n.append(a);for(const{regExp:t,replace:n}of r){const o=e.match(t);if(o&&(f.setTextContent(e.slice(o[0].length)),false!==n(a,[f],o,true)))break}if(ie(f,i,l),null!==a.getParent()&&e.length>0){const e=a.getPreviousSibling();if(!c&&(es(e)||bt$2(e)||Rt$1(e))){let t=e;if(Rt$1(e)){const n=e.getLastDescendant();t=null==n?null:Jc(n,It$2);}null!=t&&t.getTextContentSize()>0&&(t.splice(t.getChildrenSize(),0,[$t(t),...a.getChildren()]),a.remove());}}}function ae(t){const e=new Set,n=t.getTextContent();let o=n.indexOf("\t");for(;-1!==o;)e.add(o),e.add(o+1),o=n.indexOf("\t",o+1);t.splitText(...e).forEach(t=>{"\t"===t.getTextContent()&&t.replace(tr());});}function ue(t,...e){const n=new URL("https://lexical.dev/docs/error"),o=new URLSearchParams;o.append("code",t);for(const t of e)o.append("v",t);throw n.search=o.toString(),Error(`Minified Lexical error #${t}; visit ${n.toString()} for the full message or use the non-minified dev environment for full errors and additional helpful warnings.`)}function de(t,e,n,o,r){const s=t.getParent();if(!Kl(s)||t.getFirstChild()!==e)return  false;const i=e.getTextContent();if(!r&&" "!==i[n-1])return  false;for(const{regExp:s,replace:l}of o){const o=i.match(s),c=r||o&&o[0].endsWith(" ")?n:n-1;if(o&&o[0].length===c){const r=e.getNextSiblings(),[s,i]=e.splitText(n);if(false!==l(t,i?[i,...r]:r,o,false))return s.remove(),true}}return  false}function ge(t,e,n,o,r){const s=t.getParent();if(!Kl(s)||t.getFirstChild()!==e)return  false;const i=e.getTextContent();if(!r&&" "!==i[n-1])return  false;for(const{regExpStart:s,replace:l,regExpEnd:c}of o){if(c&&!("optional"in c)||c&&"optional"in c&&!c.optional)continue;const o=i.match(s);if(o){const s=r||o[0].endsWith(" ")?n:n-1;if(o[0].length!==s)continue;const i=e.getNextSiblings(),[c,f]=e.splitText(n);if(false!==l(t,f?[f,...i]:i,o,null,null,false))return c.remove(),true}}return  false}function he(t,e){let n=0;const o=t.getTextContent();for(let t=0;t<e;t++)"`"===o[t]&&n++;return n%2!=0}function pe(t,e,n){const o=n.length;for(let r=e;r>=o;r--){const e=r-o;if(xe(t,e,n,0,o)&&" "!==t[e+o])return e}return  -1}function xe(t,e,n,o,r){for(let s=0;s<r;s++)if(t[e+s]!==n[o+s])return  false;return  true}function me(t,n=Kt){const o=V(n),r=o.element.filter(t=>t.triggerOnEnter),s=Q(o.textFormat,({tag:t})=>t[t.length-1]),i=Q(o.textMatch,({trigger:t})=>t),l=new Set([" "]);for(const t of o.textFormat)l.add(t.tag.slice(-1));for(const t of o.textMatch) void 0!==t.trigger&&l.add(t.trigger);for(const e of n){const n=e.type;if("element"===n||"text-match"===n||"multiline-element"===n){const n=e.dependencies;for(const e of n)t.hasNode(e)||ue(173,e.getType());}}const f=(t,n,r)=>!!de(t,n,r,o.element)||(!!ge(t,n,r,o.multilineElement)||(!!function(t,e,n){let o=t.getTextContent();const r=n[o[e-1]];if(null==r)return  false;e<o.length&&(o=o.slice(0,e));for(const e of r){if(!e.replace||!e.regExp)continue;const n=o.match(e.regExp);if(null===n)continue;const r=n.index||0,s=r+n[0].length;let i;return 0===r?[i]=t.splitText(s):[,i]=t.splitText(r,s),i.selectNext(0,0),e.replace(i,n),true}return  false}(n,r,i)||!!function(t,n,o){const r=t.getTextContent(),s=n-1,i=r[s],l=o[i];if(!l)return  false;for(const n of l){const{tag:o}=n,l=o.length,f=s-l+1;if(l>1&&!xe(r,f,o,0,l))continue;if(" "===r[f-1])continue;const a=r[s+1];if(false===n.intraword&&a&&!X.test(a))continue;const u=t;let d=u,g=pe(r,f,o),h=d;for(;g<0&&(h=h.getPreviousSibling())&&!qi(h);)if(Xo(h)){if(h.hasFormat("code"))continue;const t=h.getTextContent();d=h,g=pe(t,t.length,o);}if(g<0)continue;if(d===u&&g+l===f)continue;const p=d.getTextContent();if(g>0&&p[g-1]===i)continue;const x=p[g-1];if(false===n.intraword&&x&&!X.test(x))continue;if(!n.format.includes("code")&&he(d,g))continue;const m=u.getTextContent(),T=m.slice(0,f)+m.slice(s+1);u.setTextContent(T);const $=d===u?T:p;d.setTextContent($.slice(0,g)+$.slice(g+l));const E=Lr(),v=Dr();el(v);const y=s-l*(d===u?2:1)+1;v.anchor.set(d.__key,g,"text"),v.focus.set(u.__key,y,"text");for(const t of n.format)v.formatText(t,z$1[t]);v.anchor.set(v.focus.key,v.focus.offset,v.focus.type);for(const t of n.format)v.hasFormat(t)&&v.toggleFormat(t);return cr(E)&&(v.format=E.format),true}return  false}(n,r,s)));return ku(t.registerUpdateListener(({tags:n,dirtyLeaves:o,editorState:r,prevEditorState:s})=>{if(n.has(vo)||n.has(yo))return;if(t.isComposing())return;const i=n.has(Eo),c=r.read(Lr),a=s.read(Lr);if(!cr(a)||!cr(c)||!c.isCollapsed()||c.is(a)&&!i)return;const u=c.anchor.key,d=c.anchor.offset,g=r._nodeMap.get(u);if(Xo(g)&&o.has(u)&&(i||1===d||!(d>a.anchor.offset+1))){if(i){const t=r.read(()=>g.getTextContent())[d-1];if(!l.has(t))return}t.update(()=>{if(!se(g))return;const t=g.getParent();null===t||Ne(t)||f(t,g,c.anchor.offset)&&Ol(mo);});}}),t.registerCommand(cn$1,t=>{if(null!==t&&t.shiftKey)return  false;const n=Lr();if(!cr(n)||!n.isCollapsed())return  false;const s=n.anchor.offset,i=n.anchor.getNode();if(!Xo(i)||!se(i))return  false;const l=i.getParent();if(null===l||Ne(l))return  false;return s===i.getTextContent().length&&(!(!ge(l,i,s,o.multilineElement,true)&&!de(l,i,s,r,true))&&(null!==t&&t.preventDefault(),true))},rs))}function Ce(t,e=Kt,n,o=false,r=false){const s=o?t:qt(t,r),i=tl();i.clear(),le(s,i,e,o),null!==Lr()&&i.selectStart();}function Te(t=Kt,e,n=false){const o=function(t,e=false){const n=V(t),o=[...n.multilineElement,...n.element],r=!e,s=n.textFormat.filter(t=>1===t.format.length).sort((t,e)=>Number(t.format.includes("code"))-Number(e.format.includes("code")));return t=>{const i=[],l=(t||tl()).getChildren();for(let t=0;t<l.length;t++){const c=l[t],f=Qt(c,o,s,n.textMatch,e);null!=f&&i.push(r&&t>0&&!et(c)&&!et(l[t-1])?"\n".concat(f):f);}return i.join("\n")}}(t,n);return o(e)}

	/**
	 * Copyright (c) Meta Platforms, Inc. and affiliates.
	 *
	 * This source code is licensed under the MIT license found in the
	 * LICENSE file in the root directory of this source tree.
	 *
	 */

	function E(t,e,n,o,r){if(null===t||0===n.size&&0===o.size&&!r)return 0;const a=e._selection,i=t._selection;if(r)return 1;if(!(cr(a)&&cr(i)&&i.isCollapsed()&&a.isCollapsed()))return 0;const l=function(t,e,n){const o=t._nodeMap,r=[];for(const t of e){const e=o.get(t);void 0!==e&&r.push(e);}for(const[t,e]of n){if(!e)continue;const n=o.get(t);void 0===n||zi(n)||r.push(n);}return r}(e,n,o);if(0===l.length)return 0;if(l.length>1){const n=e._nodeMap,o=n.get(a.anchor.key),r=n.get(i.anchor.key);return o&&r&&!t._nodeMap.has(o.__key)&&Xo(o)&&1===o.__text.length&&1===a.anchor.offset?2:0}const s=l[0],u=t._nodeMap.get(s.__key);if(!Xo(u)||!Xo(s)||u.__mode!==s.__mode)return 0;const d=u.__text,c=s.__text;if(d===c)return 0;const p=a.anchor,f=i.anchor;if(p.key!==f.key||"text"!==p.type)return 0;const h=p.offset,m=f.offset,g=c.length-d.length;return 1===g&&m===h-1?2:-1===g&&m===h+1?3:-1===g&&m===h?4:0}function D(t,e,n){let o=n(),r=0,a=o,i=0,l=null;return (s,u,d,c,p,f)=>{const h=n();if(f.has(wo)&&(a=o,i=r,l=s),f.has(yo))return r=0,o=h,2;f.has(Eo)&&l&&(o=a,r=i,s=l);const m=f.has(Co)||f.has(So)?0:E(s,u,c,p,t.isComposing()),w=(()=>{const n=null===d||d.editor===t,a=f.has(mo);if(!a&&n&&f.has(xo))return 0;if(1===m)return 2;if(null===s)return 1;const i=u._selection;if(!(c.size>0||p.size>0))return null!==i?0:2;const l=e;if(false===a&&0!==m&&m===r&&h<o+l&&n)return 0;if(1===c.size){if(function(t,e,n){const o=e._nodeMap.get(t),r=n._nodeMap.get(t),a=e._selection,i=n._selection;return !(cr(a)&&cr(i)&&"element"===a.anchor.type&&"element"===a.focus.type&&"text"===i.anchor.type&&"text"===i.focus.type||!Xo(o)||!Xo(r)||o.__parent!==r.__parent)&&JSON.stringify(e.read(()=>o.exportJSON()))===JSON.stringify(n.read(()=>r.exportJSON()))}(Array.from(c)[0],s,u))return 0}return 1})();return o=h,r=m,w}}function M(t,e){t.undoStack=[],t.redoStack=[],t.current=null;}function O(t,e,n,o=Date.now,r,f=null){const h=D(t,n,o);return ku(t.registerCommand(Qe$2,()=>(function(t,e,n){const o=e.redoStack,r=e.undoStack;if(0!==r.length){const a=e.current,i=r.pop();null!==a&&(o.push(a),t.dispatchCommand(wn$1,true)),0===r.length&&t.dispatchCommand(En$2,false),e.current=i||null,i&&i.editor.setEditorState(i.editorState,{tag:yo});}}(t,e),true),os),t.registerCommand(Ze$2,()=>(function(t,e,n){const o=e.redoStack,r=e.undoStack;if(0!==o.length){const a=e.current;null!==a&&(r.push(a),t.dispatchCommand(En$2,true));const i=o.pop();0===o.length&&t.dispatchCommand(wn$1,false),e.current=i||null,i&&i.editor.setEditorState(i.editorState,{tag:yo});}}(t,e),true),os),t.registerCommand(bn$2,()=>(M(e),false),os),t.registerCommand(Nn$2,()=>(M(e),t.dispatchCommand(wn$1,false),t.dispatchCommand(En$2,false),true),os),t.registerUpdateListener(({editorState:n,prevEditorState:o,dirtyLeaves:r,dirtyElements:a,tags:i})=>{const l=e.current,s=e.redoStack,u=e.undoStack,d=null===l?null:l.editorState;if(null!==l&&n===d)return;const g=h(o,n,l,r,a,i);if(1===g){if(0!==s.length&&(e.redoStack=[],t.dispatchCommand(wn$1,false)),null!==l){u.push({...l});const e="number"==typeof f||null===f?f:f.peek();null!==e&&u.length>e&&u.splice(0,u.length-e),t.dispatchCommand(En$2,true);}}else if(2===g)return;e.current={editor:t,editorState:n};}))}function z(){return {current:null,redoStack:[],undoStack:[]}}

	/**
	 * Lexical KB editor entry — rolled up to UMD as window.EspoLexical
	 * Markdown: https://lexical.dev/docs/packages/lexical-markdown
	 */

	const theme = {
	    paragraph: 'kb-lex-p',
	    quote: 'kb-lex-quote',
	    heading: {
	        h1: 'kb-lex-h1',
	        h2: 'kb-lex-h2',
	        h3: 'kb-lex-h3',
	    },
	    list: {
	        ul: 'kb-lex-ul',
	        ol: 'kb-lex-ol',
	        listitem: 'kb-lex-li',
	        nested: {
	            listitem: 'kb-lex-nested-li',
	        },
	        listitemChecked: 'kb-lex-li-checked',
	        listitemUnchecked: 'kb-lex-li-unchecked',
	    },
	    link: 'kb-lex-link',
	    text: {
	        bold: 'kb-lex-bold',
	        italic: 'kb-lex-italic',
	        underline: 'kb-lex-underline',
	        code: 'kb-lex-code',
	        strikethrough: 'kb-lex-strike',
	    },
	    code: 'kb-lex-codeblock',
	    table: 'kb-lex-table table table-bordered',
	    tableCell: 'kb-lex-td',
	    tableCellHeader: 'kb-lex-th',
	    tableRow: 'kb-lex-tr',
	    tableSelected: 'kb-lex-table-selected',
	    tableCellSelected: 'kb-lex-td-selected',
	    tableSelection: 'kb-lex-table-selection',
	};

	function createNodes() {
	    return [
	        _t$2,
	        Nt$2,
	        Et$1,
	        Pt$2,
	        Z$1,
	        ve,
	        ke,
	        Zn,
	        ot$1,
	        ut$1,
	    ];
	}

	/**
	 * Lexical 0.48+ registerLink expects signal-like stores (from LinkExtension),
	 * not a plain options bag. Provide minimal stores so peek()/value work.
	 * @see node_modules/@lexical/link registerLink(editor, stores)
	 */
	function createLinkStores() {
	    const validateUrl = (url) => {
	        if (!url || typeof url !== 'string') {
	            return false;
	        }

	        const trimmed = url.trim();

	        // Espo attachment / relative / in-app URLs
	        if (
	            trimmed.startsWith('?') ||
	            trimmed.startsWith('/') ||
	            trimmed.startsWith('#') ||
	            trimmed.startsWith('mailto:') ||
	            trimmed.startsWith('tel:')
	        ) {
	            return true;
	        }

	        try {
	            // eslint-disable-next-line no-new
	            new URL(trimmed);
	            return true;
	        } catch (e) {
	            return false;
	        }
	    };

	    return {
	        validateUrl: {
	            peek: () => validateUrl,
	            get value() {
	                return validateUrl;
	            },
	        },
	        attributes: {
	            peek: () => undefined,
	            get value() {
	                return undefined;
	            },
	        },
	    };
	}

	/**
	 * Fallback link command registration if registerLink signature changes again.
	 */
	function registerLinkFallback(editor) {
	    return editor.registerCommand(
	        nt$2,
	        (payload) => {
	            if (payload === null) {
	                it$2(null);
	                return true;
	            }

	            if (typeof payload === 'string') {
	                it$2(payload);
	                return true;
	            }

	            if (payload && typeof payload === 'object') {
	                const {url, target, rel, title} = payload;
	                it$2(url, {rel, target, title});
	                return true;
	            }

	            return false;
	        },
	        os
	    );
	}

	/**
	 * Minimal signal store for Lexical 0.48 plugins that call .peek().
	 * @param {*} initial
	 */
	function createSignal(initial) {
	    let value = initial;

	    return {
	        peek: () => value,
	        get value() {
	            return value;
	        },
	        set(next) {
	            value = next;
	        },
	    };
	}

	/**
	 * Fallback INSERT_TABLE if registerTablePlugin signature changes.
	 */
	function registerTableFallback(editor) {
	    return editor.registerCommand(
	        ct$1,
	        (payload) => {
	            if (!payload) {
	                return false;
	            }

	            const rows = Number(payload.rows) || 0;
	            const columns = Number(payload.columns) || 0;

	            if (rows < 1 || columns < 1) {
	                return false;
	            }

	            const includeHeaders =
	                payload.includeHeaders === undefined ? true : payload.includeHeaders;

	            const tableNode = gt$1(rows, columns, includeHeaders);
	            Jr([tableNode, ts()]);

	            return true;
	        },
	        os
	    );
	}

	/**
	 * @param {object} options
	 * @param {HTMLElement} options.element
	 * @param {string} [options.namespace]
	 * @param {() => void} [options.onChange]
	 * @param {boolean} [options.editable]
	 * @param {boolean} [options.markdownShortcuts=true]
	 */
	function createKbEditor(options) {
	    const {
	        element,
	        namespace = 'EspoKB',
	        onChange,
	        editable = true,
	        markdownShortcuts = true,
	    } = options;

	    const editor = ps({
	        namespace,
	        theme,
	        editable,
	        nodes: createNodes(),
	        onError(error) {
	            console.error('[EspoLexical]', error);
	        },
	    });

	    editor.setRootElement(element);

	    const historyState = z();

	    let linkUnregister;
	    try {
	        linkUnregister = ut$2(editor, createLinkStores());
	    } catch (e) {
	        console.warn('[EspoLexical] registerLink failed, using fallback', e);
	        linkUnregister = registerLinkFallback(editor);
	    }

	    let tableUnregister;
	    try {
	        // Lexical 0.48: hasNestedTables is a signal store with .peek()
	        tableUnregister = ku(
	            ho(editor, {
	                hasNestedTables: createSignal(false),
	            }),
	            uo(editor, true)
	        );
	    } catch (e) {
	        console.warn('[EspoLexical] registerTablePlugin failed, using fallback', e);
	        tableUnregister = registerTableFallback(editor);
	    }

	    const unregisters = [
	        qt$2(editor),
	        re$1(editor),
	        linkUnregister,
	        tableUnregister,
	        O(editor, historyState, 300),
	        editor.registerUpdateListener(() => {
	            if (typeof onChange === 'function') {
	                onChange();
	            }
	        }),
	    ];

	    // https://lexical.dev/docs/packages/lexical-markdown#shortcuts
	    if (markdownShortcuts) {
	        unregisters.push(me(editor, Kt));
	    }

	    const unregister = ku(...unregisters);

	    return {
	        editor,
	        destroy() {
	            unregister();
	            editor.setRootElement(null);
	        },
	        setEditable(value) {
	            editor.setEditable(!!value);
	        },
	        /** Import HTML (legacy Summernote / Html projection). */
	        setHtml(html) {
	            editor.update(() => {
	                const root = tl();
	                root.clear();

	                const source = (html || '').trim();

	                if (!source) {
	                    root.append(ts());
	                    return;
	                }

	                const parser = new DOMParser();
	                const dom = parser.parseFromString(source, 'text/html');
	                const nodes = kn$1(editor, dom);

	                if (!nodes.length) {
	                    root.append(ts());
	                    return;
	                }

	                Jr(nodes);
	            }, {discrete: true});
	        },
	        getHtml() {
	            let html = '';
	            editor.getEditorState().read(() => {
	                html = On$1(editor, null);
	            });

	            if (html === '<p><br></p>' || html === '<p></p>') {
	                return '';
	            }

	            return html;
	        },
	        // https://lexical.dev/docs/packages/lexical-markdown#import-and-export
	        // Frontmatter is stripped before import (remark-frontmatter style).
	        setMarkdown(markdown) {
	            const body = stripFrontmatter(markdown || '');
	            editor.update(() => {
	                Ce(body, Kt);
	            }, {discrete: true});
	        },
	        getMarkdown() {
	            let md = '';
	            editor.getEditorState().read(() => {
	                md = Te(Kt);
	            });
	            return md;
	        },
	        /**
	         * Canonical Lexical editor state JSON (mentions / custom nodes later).
	         * @returns {string|null}
	         */
	        getEditorStateJSON() {
	            try {
	                return JSON.stringify(editor.getEditorState().toJSON());
	            } catch (e) {
	                console.error('[EspoLexical] getEditorStateJSON', e);
	                return null;
	            }
	        },
	        /**
	         * @param {string|object|null} stateJSON
	         * @returns {boolean}
	         */
	        setEditorStateJSON(stateJSON) {
	            if (!stateJSON) {
	                return false;
	            }

	            try {
	                const parsed = typeof stateJSON === 'string' ? JSON.parse(stateJSON) : stateJSON;
	                editor.setEditorState(editor.parseEditorState(parsed));
	                return true;
	            } catch (e) {
	                console.error('[EspoLexical] setEditorStateJSON', e);
	                return false;
	            }
	        },
	        isEmpty() {
	            let empty = true;
	            editor.getEditorState().read(() => {
	                empty = tl().getTextContent().trim() === '';
	            });
	            return empty;
	        },
	        focus() {
	            editor.focus();
	        },
	        formatBold() {
	            editor.dispatchCommand(Ge$2, 'bold');
	        },
	        formatItalic() {
	            editor.dispatchCommand(Ge$2, 'italic');
	        },
	        formatUnderline() {
	            editor.dispatchCommand(Ge$2, 'underline');
	        },
	        formatStrikethrough() {
	            editor.dispatchCommand(Ge$2, 'strikethrough');
	        },
	        formatCode() {
	            editor.dispatchCommand(Ge$2, 'code');
	        },
	        insertUnorderedList() {
	            editor.dispatchCommand(te$1, undefined);
	        },
	        insertOrderedList() {
	            editor.dispatchCommand(ee$1, undefined);
	        },
	        removeList() {
	            editor.dispatchCommand(ne$1, undefined);
	        },
	        undo() {
	            editor.dispatchCommand(Qe$2, undefined);
	        },
	        redo() {
	            editor.dispatchCommand(Ze$2, undefined);
	        },
	        toggleLink(url) {
	            editor.dispatchCommand(nt$2, url ? url : null);
	        },
	        /**
	         * @param {{rows?: number|string, columns?: number|string, includeHeaders?: boolean}|number} [rowsOrOpts]
	         * @param {number|string} [columns]
	         */
	        insertTable(rowsOrOpts = 3, columns = 3) {
	            let rows = 3;
	            let cols = 3;
	            let includeHeaders = true;

	            if (rowsOrOpts && typeof rowsOrOpts === 'object') {
	                rows = Number(rowsOrOpts.rows) || 3;
	                cols = Number(rowsOrOpts.columns) || 3;
	                if (rowsOrOpts.includeHeaders !== undefined) {
	                    includeHeaders = !!rowsOrOpts.includeHeaders;
	                }
	            } else {
	                rows = Number(rowsOrOpts) || 3;
	                cols = Number(columns) || 3;
	            }

	            rows = Math.min(Math.max(rows, 1), 20);
	            cols = Math.min(Math.max(cols, 1), 12);

	            editor.dispatchCommand(ct$1, {
	                rows: String(rows),
	                columns: String(cols),
	                includeHeaders,
	            });
	        },
	        insertHeading(tag) {
	            editor.update(() => {
	                const selection = Lr();
	                if (!selection) {
	                    return;
	                }
	                selection.insertNodes([kt$2(tag || 'h2')]);
	            });
	        },
	        insertQuote() {
	            editor.update(() => {
	                const selection = Lr();
	                if (!selection) {
	                    return;
	                }
	                selection.insertNodes([St$2()]);
	            });
	        },
	    };
	}

	/**
	 * YAML frontmatter before Lexical markdown.
	 * Mirrors backend $Skills/frontmatter.ts + remark-frontmatter style split:
	 * leading `---` … `---` is peeled off; Lexical only sees the body.
	 *
	 * @param {string} raw
	 * @returns {{ frontmatter: string|null, yaml: string, body: string, fields: Record<string, string> }}
	 */
	function splitFrontmatter(raw) {
	    const content = String(raw || '').replace(/^\uFEFF/, '');
	    const match = content.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n?([\s\S]*)$/);

	    if (!match) {
	        return {
	            frontmatter: null,
	            yaml: '',
	            body: content,
	            fields: {},
	        };
	    }

	    const yaml = match[1] ?? '';
	    const body = (match[2] ?? '').replace(/^\n/, '');
	    const fields = {};

	    for (const line of yaml.split(/\r?\n/)) {
	        const kv = line.match(/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/);

	        if (!kv) {
	            continue;
	        }

	        let value = (kv[2] || '').trim();

	        if (
	            (value.startsWith('"') && value.endsWith('"')) ||
	            (value.startsWith("'") && value.endsWith("'"))
	        ) {
	            value = value.slice(1, -1);
	        }

	        fields[kv[1]] = value;
	    }

	    return {
	        frontmatter: `---\n${yaml}\n---`,
	        yaml,
	        body,
	        fields,
	    };
	}

	/**
	 * @param {string} raw
	 * @returns {string} markdown body without leading YAML frontmatter
	 */
	function stripFrontmatter(raw) {
	    return splitFrontmatter(raw).body;
	}

	/**
	 * Build SKILL.md-style document. Frontmatter stays outside Lexical.
	 *
	 * @param {{name?: string, description?: string, fields?: Record<string, string>, body: string}} input
	 * @returns {string}
	 */
	function joinFrontmatter(input) {
	    const body = String(input.body || '').replace(/^\uFEFF/, '').replace(/^\n+/, '');
	    const fields = {...(input.fields || {})};

	    if (input.name != null && input.name !== '') {
	        fields.name = input.name;
	    }

	    if (input.description != null) {
	        fields.description = input.description;
	    }

	    const keys = Object.keys(fields);

	    if (!keys.length) {
	        return body;
	    }

	    const escapeYamlDoubleQuoted = (value) =>
	        String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');

	    const lines = keys.map((key) => {
	        const value = fields[key] == null ? '' : String(fields[key]);
	        const needsQuote = /[:#{}[\],&*?|>!%@`]|^\s|\s$|"|'|\n/.test(value);

	        return needsQuote
	            ? `${key}: "${escapeYamlDoubleQuoted(value)}"`
	            : `${key}: ${value}`;
	    });

	    return (
	        `---\n` +
	        `${lines.join('\n')}\n` +
	        `---\n\n` +
	        `${body.endsWith('\n') || body === '' ? body : `${body}\n`}`
	    );
	}

	/**
	 * MD → HTML via the same TRANSFORMERS used in-editor.
	 */
	function markdownToHtml(markdown) {
	    if (!markdown) {
	        return '';
	    }

	    const hold = document.createElement('div');
	    hold.style.display = 'none';
	    document.body.appendChild(hold);

	    try {
	        const tmp = createKbEditor({
	            element: hold,
	            editable: false,
	            markdownShortcuts: false,
	        });
	        tmp.setMarkdown(markdown);
	        const html = tmp.getHtml();
	        tmp.destroy();
	        return html;
	    } finally {
	        hold.remove();
	    }
	}

	function htmlToMarkdown(html) {
	    if (!html) {
	        return '';
	    }

	    const hold = document.createElement('div');
	    hold.style.display = 'none';
	    document.body.appendChild(hold);

	    try {
	        const tmp = createKbEditor({
	            element: hold,
	            editable: false,
	            markdownShortcuts: false,
	        });
	        tmp.setHtml(html);
	        const md = tmp.getMarkdown();
	        tmp.destroy();
	        return md;
	    } finally {
	        hold.remove();
	    }
	}

	const EspoLexical = {
	    createKbEditor,
	    markdownToHtml,
	    htmlToMarkdown,
	    splitFrontmatter,
	    stripFrontmatter,
	    joinFrontmatter,
	    TRANSFORMERS: Kt,
	};

	return EspoLexical;

})();
