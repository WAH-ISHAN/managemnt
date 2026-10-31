(() => {
  const shade=(hex,amount)=>{
    const value=/^#[\da-f]{6}$/i.test(hex) ? hex : '#2879e9';
    const rgb=[1,3,5].map(offset=>parseInt(value.slice(offset,offset+2),16));
    return `rgb(${rgb.map(channel=>Math.round(amount>=0 ? channel+(255-channel)*amount : channel*(1+amount))).join(',')})`;
  };
  function polygon(ctx,points,fill) {
    ctx.beginPath(); points.forEach(([x,y],index)=>index ? ctx.lineTo(x,y) : ctx.moveTo(x,y));
    ctx.closePath(); ctx.fillStyle=fill; ctx.fill();
  }
  // The front face uses Chart.js's animated coordinates and its unmodified scale.
  // Back faces add depth; native bar elements still handle tooltips and legends.
  window.LifeOSCashflow3D={
    id:'lifeos-cashflow-depth',
    beforeDatasetsDraw(chart,args,options) {
      if (!options.enabled || chart.config.type!=='bar') return;
      const {ctx,chartArea}=chart;
      const active=new Set(chart.getActiveElements().map(item=>`${item.datasetIndex}:${item.index}`));
      ctx.save();
      try {
        ctx.beginPath();ctx.rect(chartArea.left,chartArea.top-12,chartArea.right-chartArea.left+12,chartArea.bottom-chartArea.top+24);ctx.clip();
        for (const meta of chart.getSortedVisibleDatasetMetas()) {
          if (meta.type!=='bar') continue;
          const dataset=chart.data.datasets[meta.index];
          const color=dataset.lifeosDepthColor || '#2879e9';
          meta.data.forEach((bar,index)=>{
            if (bar.skip || !chart.getDataVisibility(index)) return;
            const {x,y,base,width}=bar.getProps(['x','y','base','width'],false);
            if (![x,y,base,width].every(Number.isFinite) || width<=0 || Math.abs(base-y)<.75) return;
            const left=x-width/2,right=x+width/2,top=Math.min(y,base),height=Math.abs(base-y);
            const dx=Math.min(9,width*.28),dy=dx*.65;
            const hovered=active.has(`${meta.index}:${index}`);
            // A floor shadow sits below the bar without adding an apparent value.
            ctx.fillStyle=options.dark ? 'rgba(0,0,0,.18)' : 'rgba(30,64,120,.08)';
            ctx.beginPath();ctx.ellipse(x+dx/2,base+3,width*.66+dx,3,0,0,Math.PI*2);ctx.fill();
            const side=ctx.createLinearGradient(right,0,right+dx,0);
            side.addColorStop(0,shade(color,-.18));side.addColorStop(1,shade(color,-.42));
            polygon(ctx,[[right,y],[right+dx,y-dy],[right+dx,base-dy],[right,base]],side);
            const front=ctx.createLinearGradient(left,top,right,top+height);
            front.addColorStop(0,shade(color,hovered ? .24 : .12));front.addColorStop(.48,color);front.addColorStop(1,shade(color,-.12));
            ctx.fillStyle=front;ctx.fillRect(left,top,width,height);
            polygon(ctx,[[left,y],[left+dx,y-dy],[right+dx,y-dy],[right,y]],shade(color,hovered ? .45 : .30));
            ctx.strokeStyle=options.dark ? 'rgba(255,255,255,.24)' : 'rgba(255,255,255,.34)';ctx.lineWidth=1;
            ctx.beginPath();ctx.moveTo(left+.5,y);ctx.lineTo(right,y);ctx.lineTo(right+dx,y-dy);ctx.stroke();
          });
        }
      } finally {ctx.restore();}
      return false;
    }
  };
})();
