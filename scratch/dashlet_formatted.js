"advanced:views/dashlets/report",["views/dashlets/abstract/base","search-manager","advanced:report-helper"],function(t,e,i){
return t.extend({
name:"Report",optionsView:"advanced:views/dashlets/options/report",templateContent:'<div class="report-results-container" style="height: 100%;
"></div>',totalFontSizeMultiplier:1.5,totalLineHeightMultiplier:1.1,totalMarginMultiplier:.4,totalOnlyFontSizeMultiplier:4,totalLabelMultiplier:.6,total2LabelMultiplier:.4,rowActionsView:!1,setup:function(){
this.optionsFields.report={
type:"link",entity:"Report",required:!0,view:"advanced:views/report/fields/dashlet-select"
},this.optionsFields.column={
type:"enum",options:[]
},this.reportHelper=new i(this.getMetadata(),this.getLanguage(),this.getDateTime(),this.getConfig(),this.getPreferences())
},afterAdding:function(){
this.getParentView().actionOptions()
},getListLayout:function(){
const t=this.getOption("entityType"),e=[],i=Espo.Utils.cloneDeep(this.columnsData||{

});
return(this.columns||[]).forEach(s=>{
const a=i[s]||{

};
if(a.name=s,~s.indexOf(".")){
const e=s.split(".");
a.name=s.replace(".","_"),a.notSortable=!0;
const i=e[0],n=e[1],o=this.getMetadata().get(`entityDefs.${
t
}.links.${
i
}.entity`);
a.customLabel=this.translate(i,"links",t)+" . "+this.translate(n,"fields",o);
const l=this.getMetadata().get(`entityDefs.${
o
}.fields.${
n
}.type`);
"enum"===l?(a.view="views/fields/foreign-enum",a.options={
params:{
link:i,field:n
}
}):"image"===l?(a.view="views/fields/image",a.options={
foreignScope:o
}):"file"===l?(a.view="views/fields/file",a.options={
foreignScope:o
}):"date"===l?(a.view="views/fields/foreign-date",a.notSortable=!1,a.options={
params:{
link:i,field:n
}
}):"datetime"===l?(a.view="views/fields/foreign-datetime",a.options={
params:{
link:i,field:n
}
},a.notSortable=!1):"link"===l?a.view="advanced:views/fields/foreign-link":"email"===l?(a.view="views/fields/email",a.notSortable=!1):"phone"===l?(a.view="views/fields/phone",a.notSortable=!1):"array"===l?(a.view="views/fields/foreign-array",a.options={
params:{
link:i,field:n
}
}):"multiEnum"===l?(a.view="views/fields/foreign-multi-enum",a.options={
params:{
link:i,field:n
}
}):"checklist"===l?(a.view="views/fields/foreign-checklist",a.options={
params:{
link:i,field:n
}
}):"urlMultiple"===l?(a.view="views/fields/foreign-url-multiple",a.options={
params:{
link:i,field:n
}
}):"varchar"===l?(a.view="views/fields/varchar",a.notSortable=!1):"bool"===l?(a.view="views/fields/bool",a.notSortable=!1):"currencyConverted"===l&&(a.view="views/fields/currency-converted",a.notSortable=!1)
}e.push(a)
}),e
},displayError:function(t){
t=t||"error",this.$el.find(".report-results-container").html(this.translate(t,"errorMessages","Report"))
},displayTotal:function(t,e){
const i=this.getThemeManager().getFontSizeFactor?this.getThemeManager().getFontSizeFactor():1,s=(this.getThemeManager().getParam("fontSize")||14)*i,a=100/t.length*i;
let n;
if(!e){
this.$container.empty();
let o=s*this.totalOnlyFontSizeMultiplier;
t.length>1&&(o=Math.round(o/(Math.log(t.length+1)/Math.log(2.3))),n=Math.round(o*this.total2LabelMultiplier)),this.$container.css("height","100%");
const l=$("<div>").css("text-align","center").css("table-layout","fixed").css("display","table").css("width","100%").css("height","100%");
return t.forEach(r=>{
const d=r.stringValue,h=r.color,c=$("<div>").css("display","table-cell").css("padding-bottom",1.5*s+"px").css("vertical-align","middle");
a<100*i&&c.css("width",a.toString()+"%");
const p=$('<div class="total-value-text numeric-text">').css("font-size",o.toPrecision(4)+"px").html(d.toString());
if(r.stringOriginalValue&&p.attr("title",r.stringOriginalValue),h?p.css("color",h):e||p.addClass("text-primary"),t.length>1){
const t=$("<div>").css("font-size",n.toString()+"px").css("max-height","1.3em").css("overflow","hidden").css("user-select","none").addClass("text-muted").html(r.columnLabel);
c.append(t)
}c.append(p),l.append(c)
}),this.$container.append(l),this.totalFontSize=o,this.controlTotalTextOverflow(),this.stopListening(this,"resize",this.controlTotalTextOverflow.bind(this)),void this.listenTo(this,"resize",this.controlTotalTextOverflow.bind(this))
}const o=s*this.totalFontSizeMultiplier;
t.length>1&&(n=Math.round(o*this.totalLabelMultiplier));
const l=this.getContainerTotalHeight(t.length>1)+"px",r=$("<div>").css("text-align","center").css("display","table").css("width","100%");
this.$totalContainer.css("height",l),this.$container.css("height",`calc(100% - ${
l
})`),t.forEach(e=>{
const i=e.stringValue,s=e.color,d=$("<div>").html(i.toString());
let h="";
if(e.stringOriginalValue&&(h=e.stringOriginalValue),1===t.length){
d.addClass("pull-right");
const t=h;
h=this.translate("Total","labels","Report"),t&&(h=h+": "+t)
}d.attr("title",h).addClass("text-primary numeric-text").css("font-size",Math.ceil(o)+"px"),s&&d.css("color",s),1===t.length&&d.css("line-height",l);
const c=$("<div>").css("display","table-cell");
if(a<100&&c.css("width",a.toString()+"%"),t.length>1){
const t=$("<div>").css("font-size",n.toString()+"px").css("max-height","1.2em").css("overflow","hidden").css("user-select","none").addClass("text-muted").html(e.columnLabel);
c.append(t)
}c.append(d),r.append(c)
}),this.$totalContainer.append(r)
},controlTotalTextOverflow:function(){
let{
totalFontSize:t
}=this;
const e=this.$el.find(".total-value-text");
e.css("font-size",t+"px");
const i=()=>{
let s=!1;
e.each((t,e)=>{
e.scrollWidth>e.clientWidth&&(s=!0)
}),s&&(t--,e.css("font-size",t+"px"),i())
};
i()
},getContainerTotalHeight:function(t){
const e=this.getThemeManager().getParam("fontSize")||14,i=e*this.totalFontSizeMultiplier,s=e*this.totalMarginMultiplier;
let a=Math.ceil(i*this.totalLineHeightMultiplier+s);
return t&&(a+=a*this.totalLabelMultiplier),a+=3*this.totalFontSizeMultiplier,a
},actionRefresh:function(){
this.hasView("reportChart")&&this.clearView("reportChart"),this.reRender()
},afterRender:function(){
this.$container=this.$el.find(".report-results-container"),this.run()
},getCollectionUrl:function(){
return"Report/action/runList?id="+this.getOption("reportId")
},getGridReportUrl:function(){
return"Report/action/run"
},getGridReportRequestData:function(t){
return{
id:this.getOption("reportId"),where:t
}
},setContainerHeight:function(){
"List"===this.getOption("type")?this.$container.css("height","auto"):this.$container.css("height","100%")
},run:async function(){
if(!this.getOption("reportId"))return void this.displayError("selectReport");
const t=this.getOption("entityType");
if(!t)return void this.displayError();
const i=this.getOption("type");
if(!i)return void this.displayError();
this.setContainerHeight();
const s=await this.getCollectionFactory().create(t),a=new e(s,"report",null,this.getDateTime());
"setTimeZone"in a&&a.setTimeZone(null);
let n=null;
if(this.getOption("filtersData")&&(a.setAdvanced(this.getOption("filtersData")),n=a.getWhere()),"List"===i){
s.url=this.getCollectionUrl(),s.where=n,s.setOrder&&s.setOrder(null,null,!0),this.collectionMaxSize&&(s.maxSize=this.collectionMaxSize);
const t={
where:s.getWhere(),offset:s.offset,maxSize:s.maxSize
},e=await Espo.Ajax.getRequest(s.url,t),i=this.columns=e.columns;
this.columnsData=e.columnsData||{

};
const a=s.prepareAttributes?s.prepareAttributes(e):s.parse(e);
if(s.set(a),!i)return void this.displayError();
if(this.getOption("displayOnlyCount")){
const t={
stringValue:this.reportHelper.formatNumber(s.total,!1,this.getOption("useSiMultiplier"))
};
return this.getOption("useSiMultiplier")&&(t.stringOriginalValue=this.reportHelper.formatNumber(s.total,!1)),void this.displayTotal([t])
}this.createView("list","views/record/list",{
selector:".report-results-container",collection:s,listLayout:this.getListLayout(),checkboxes:!1,rowActionsView:this.rowActionsView,displayTotalCount:!1
},t=>{
t.render()
})
}if("Grid"===i||"JointGrid"===i){
const t=this.getGridReportUrl(),e=await Espo.Ajax.getRequest(t,this.getGridReportRequestData(n));
if(!e.depth&&0!==e.depth)return void this.displayError();
const i=e.chartType||"BarHorizontal";
let s,a=!1;
this.isPanel||(s="100%",(2===e.depth||~["Pie"].indexOf(i))&&(a=!0));
let o,l,r=this.getOption("column");
if(!r){
const t=this.reportHelper.getChartColumnGroupList(e);
t.length&&(o=t[0].columnList,l=t[0].secondColumnList,r=t[0].column,r||this.isPanel||(a=!0))
}const d=e.numericColumnList||e.columnList,h=[];
if("Table"===this.getOption("displayType"))return void this.displayTable(e,n);
if(d.length&&(this.getOption("displayOnlyCount")||this.getOption("displayTotal"))&&d.forEach(t=>{
let i;
if(1===e.depth||0===e.depth)i=e.sums[t]||0;
else{
i=0;
for(const s in e.group1Sums)i+=e.group1Sums[s][t]
}const s=this.reportHelper.formatCellValue(i,t,e,this.getOption("useSiMultiplier"));
let a=e.chartColor;
(e.chartColors||{

})[t]&&(a=(e.chartColors||{

})[t]),e.chartType||(a=null);
let n=null;
this.getOption("useSiMultiplier")&&(n=this.reportHelper.formatCellValue(i,t,e)),h.push({
column:t,color:a,stringValue:s,columnLabel:this.reportHelper.formatColumn(t,e),stringOriginalValue:n
})
}),d.length&&this.getOption("displayOnlyCount"))return void this.displayTotal(h);
d.length&&this.getOption("displayTotal")&&(this.$totalContainer=$('<div class="report-total-container"></div>'),this.$totalContainer.insertBefore(this.$container),this.displayTotal(h,!0)),this.$el.closest(".panel-body").css({
"overflow-y":"visible","overflow-x":"visible"
});
const c=`advanced:views/report/reports/charts/grid${
e.depth
}${
Espo.Utils.camelCaseToHyphen(i)
}`;
this.createView("reportChart",c,{
selector:" .report-results-container",column:r,columnList:o,secondColumnList:l,result:e,reportHelper:this.reportHelper,height:s,fitHeight:a,colors:e.chartColors||{

},color:e.chartColor||null,defaultHeight:this.defaultHeight,isDashletMode:!0
},t=>{
this._isHidden()?(this.once("show",()=>{
this._isHidden()||t.render()
}),this.once("tab-show",()=>{
this._isHidden()||t.render()
})):t.render(),this.on("resize",()=>{
t.trigger("resize")
}),this.listenTo(t,"click-group",(t,i,s,a)=>{
this.showSubReport(n,e,t,i,s,a)
})
})
}
},_isHidden:function(){
return!1
},showSubReport:function(t,e,i,s,a,n){
let o=this.getOption("reportId"),l=this.getOption("entityType");
e.isJoint&&(o=e.columnReportIdMap[n],l=e.colum