/**
 * @provides highcharts-modules-drilldown
 * @do-not-minify
 * @nolint
 */
(function(g){function y(a,b,c){var e,a=a.rgba,b=b.rgba;e=b[3]!==1||a[3]!==1;(!b.length||!a.length)&&Highcharts.error(23);return(e?"rgba(":"rgb(")+Math.round(b[0]+(a[0]-b[0])*(1-c))+","+Math.round(b[1]+(a[1]-b[1])*(1-c))+","+Math.round(b[2]+(a[2]-b[2])*(1-c))+(e?","+(b[3]+(a[3]-b[3])*(1-c)):"")+")"}var r=function(){},n=g.getOptions(),j=g.each,k=g.extend,z=g.format,s=g.pick,o=g.wrap,h=g.Chart,m=g.seriesTypes,t=m.pie,l=m.column,u=HighchartsAdapter.fireEvent,v=HighchartsAdapter.inArray,p=[],w=1;j(["fill",
"stroke"],function(a){HighchartsAdapter.addAnimSetter(a,function(b){b.elem.attr(a,y(g.Color(b.start),g.Color(b.end),b.pos))})});k(n.lang,{drillUpText:"◁ Back to {series.name}"});n.drilldown={activeAxisLabelStyle:{cursor:"pointer",color:"#0d233a",fontWeight:"bold",textDecoration:"underline"},activeDataLabelStyle:{cursor:"pointer",color:"#0d233a",fontWeight:"bold",textDecoration:"underline"},animation:{duration:500},drillUpButton:{position:{align:"right",x:-10,y:10}}};g.SVGRenderer.prototype.Element.prototype.fadeIn=
function(a){this.attr({opacity:0.1,visibility:"inherit"}).animate({opacity:s(this.newOpacity,1)},a||{duration:250})};h.prototype.addSeriesAsDrilldown=function(a,b){this.addSingleSeriesAsDrilldown(a,b);this.applyDrilldown()};h.prototype.addSingleSeriesAsDrilldown=function(a,b){var c=a.series,e=c.xAxis,f=c.yAxis,d;d=a.color||c.color;var i,g=[],x=[],h;h=c.options._levelNumber||0;b=k({color:d,_ddSeriesId:w++},b);i=v(a,c.points);j(c.chart.series,function(a){if(a.xAxis===e)g.push(a),a.options._ddSeriesId=
a.options._ddSeriesId||w++,a.options._colorIndex=a.userOptions._colorIndex,x.push(a.options),a.options._levelNumber=a.options._levelNumber||h});d={levelNumber:h,seriesOptions:c.options,levelSeriesOptions:x,levelSeries:g,shapeArgs:a.shapeArgs,bBox:a.graphic?a.graphic.getBBox():{},color:d,lowerSeriesOptions:b,pointOptions:c.options.data[i],pointIndex:i,oldExtremes:{xMin:e&&e.userMin,xMax:e&&e.userMax,yMin:f&&f.userMin,yMax:f&&f.userMax}};if(!this.drilldownLevels)this.drilldownLevels=[];this.drilldownLevels.push(d);
d=d.lowerSeries=this.addSeries(b,!1);d.options._levelNumber=h+1;if(e)e.oldPos=e.pos,e.userMin=e.userMax=null,f.userMin=f.userMax=null;if(c.type===d.type)d.animate=d.animateDrilldown||r,d.options.animation=!0};h.prototype.applyDrilldown=function(){var a=this.drilldownLevels,b;if(a&&a.length>0)b=a[a.length-1].levelNumber,j(this.drilldownLevels,function(a){a.levelNumber===b&&j(a.levelSeries,function(a){a.options&&a.options._levelNumber===b&&a.remove(!1)})});this.redraw();this.showDrillUpButton()};h.prototype.getDrilldownBackText=
function(){var a=this.drilldownLevels;if(a&&a.length>0)return a=a[a.length-1],a.series=a.seriesOptions,z(this.options.lang.drillUpText,a)};h.prototype.showDrillUpButton=function(){var a=this,b=this.getDrilldownBackText(),c=a.options.drilldown.drillUpButton,e,f;this.drillUpButton?this.drillUpButton.attr({text:b}).align():(f=(e=c.theme)&&e.states,this.drillUpButton=this.renderer.button(b,null,null,function(){a.drillUp()},e,f&&f.hover,f&&f.select).attr({align:c.position.align,zIndex:9}).add().align(c.position,
!1,c.relativeTo||"plotBox"))};h.prototype.drillUp=function(){for(var a=this,b=a.drilldownLevels,c=b[b.length-1].levelNumber,e=b.length,f=a.series,d,i,g,h,k=function(b){var c;j(f,function(a){a.options._ddSeriesId===b._ddSeriesId&&(c=a)});c=c||a.addSeries(b,!1);if(c.type===g.type&&c.animateDrillupTo)c.animate=c.animateDrillupTo;b===i.seriesOptions&&(h=c)};e--;)if(i=b[e],i.levelNumber===c){b.pop();g=i.lowerSeries;if(!g.chart)for(d=f.length;d--;)if(f[d].options.id===i.lowerSeriesOptions.id){g=f[d];break}g.xData=
[];j(i.levelSeriesOptions,k);u(a,"drillup",{seriesOptions:i.seriesOptions});if(h.type===g.type)h.drilldownLevel=i,h.options.animation=a.options.drilldown.animation,g.animateDrillupFrom&&g.chart&&g.animateDrillupFrom(i);h.options._levelNumber=c;g.remove(!1);if(h.xAxis)d=i.oldExtremes,h.xAxis.setExtremes(d.xMin,d.xMax,!1),h.yAxis.setExtremes(d.yMin,d.yMax,!1)}this.redraw();this.drilldownLevels.length===0?this.drillUpButton=this.drillUpButton.destroy():this.drillUpButton.attr({text:this.getDrilldownBackText()}).align();
p.length=[]};l.prototype.supportsDrilldown=!0;l.prototype.animateDrillupTo=function(a){if(!a){var b=this,c=b.drilldownLevel;j(this.points,function(a){a.graphic&&a.graphic.hide();a.dataLabel&&a.dataLabel.hide();a.connector&&a.connector.hide()});setTimeout(function(){b.points&&j(b.points,function(a,b){var d=b===(c&&c.pointIndex)?"show":"fadeIn",i=d==="show"?!0:void 0;if(a.graphic)a.graphic[d](i);if(a.dataLabel)a.dataLabel[d](i);if(a.connector)a.connector[d](i)})},Math.max(this.chart.options.drilldown.animation.duration-
50,0));this.animate=r}};l.prototype.animateDrilldown=function(a){var b=this,c=this.chart.drilldownLevels,e,f=this.chart.options.drilldown.animation,d=this.xAxis;if(!a)j(c,function(a){if(b.options._ddSeriesId===a.lowerSeriesOptions._ddSeriesId)e=a.shapeArgs,e.fill=a.color}),e.x+=s(d.oldPos,d.pos)-d.pos,j(this.points,function(a){a.graphic&&a.graphic.attr(e).animate(k(a.shapeArgs,{fill:a.color}),f);a.dataLabel&&a.dataLabel.fadeIn(f)}),this.animate=null};l.prototype.animateDrillupFrom=function(a){var b=
this.chart.options.drilldown.animation,c=this.group,e=this;j(e.trackerGroups,function(a){if(e[a])e[a].on("mouseover")});delete this.group;j(this.points,function(e){var d=e.graphic,i=function(){d.destroy();c&&(c=c.destroy())};d&&(delete e.graphic,b?d.animate(k(a.shapeArgs,{fill:a.color}),g.merge(b,{complete:i})):(d.attr(a.shapeArgs),i()))})};t&&k(t.prototype,{supportsDrilldown:!0,animateDrillupTo:l.prototype.animateDrillupTo,animateDrillupFrom:l.prototype.animateDrillupFrom,animateDrilldown:function(a){var b=
this.chart.drilldownLevels[this.chart.drilldownLevels.length-1],c=this.chart.options.drilldown.animation,e=b.shapeArgs,f=e.start,d=(e.end-f)/this.points.length;if(!a)j(this.points,function(a,h){a.graphic.attr(g.merge(e,{start:f+h*d,end:f+(h+1)*d,fill:b.color}))[c?"animate":"attr"](k(a.shapeArgs,{fill:a.color}),c)}),this.animate=null}});g.Point.prototype.doDrilldown=function(a,b){for(var c=this.series.chart,e=c.options.drilldown,f=(e.series||[]).length,d;f--&&!d;)e.series[f].id===this.drilldown&&v(this.drilldown,
p)===-1&&(d=e.series[f],p.push(this.drilldown));u(c,"drilldown",{point:this,seriesOptions:d,category:b});d&&(a?c.addSingleSeriesAsDrilldown(this,d):c.addSeriesAsDrilldown(this,d))};g.Axis.prototype.drilldownCategory=function(a){j(this.ticks[a].label.ddPoints,function(b){b.series&&b.series.visible&&b.doDrilldown&&b.doDrilldown(!0,a)});this.chart.applyDrilldown()};o(g.Point.prototype,"init",function(a,b,c,e){var f=a.call(this,b,c,e),a=b.chart,c=(c=b.xAxis&&b.xAxis.ticks[e])&&c.label;if(f.drilldown){if(g.addEvent(f,
"click",function(){f.doDrilldown()}),c){if(!c.basicStyles)c.basicStyles=g.merge(c.styles);c.addClass("highcharts-drilldown-axis-label").css(a.options.drilldown.activeAxisLabelStyle).on("click",function(){b.xAxis.drilldownCategory(e)});if(!c.ddPoints)c.ddPoints=[];c.ddPoints.push(f)}}else if(c&&c.basicStyles)c.styles={},c.css(c.basicStyles);return f});o(g.Series.prototype,"drawDataLabels",function(a){var b=this.chart.options.drilldown.activeDataLabelStyle;a.call(this);j(this.points,function(a){if(a.drilldown&&
a.dataLabel)a.dataLabel.attr({"class":"highcharts-drilldown-data-label"}).css(b).on("click",function(){a.doDrilldown()})})});var q,n=function(a){a.call(this);j(this.points,function(a){a.drilldown&&a.graphic&&a.graphic.attr({"class":"highcharts-drilldown-point"}).css({cursor:"pointer"})})};for(q in m)m[q].prototype.supportsDrilldown&&o(m[q].prototype,"drawTracker",n)})(Highcharts);
