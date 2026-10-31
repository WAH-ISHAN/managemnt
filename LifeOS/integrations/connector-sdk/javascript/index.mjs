import { createHmac, timingSafeEqual } from 'node:crypto';

/** Server-only HTTP/JSON client. Never import a site token into browser bundles. */
export function createLifeOS({gateway,siteId,token,webhookSecret,fetchImpl=fetch}) {
  const base=new URL(gateway);
  if(base.protocol!=='https:'||base.username||base.password||base.search||base.hash||!/^site_[a-f0-9]{16}$/.test(siteId)||!/^\S{32,4096}$/.test(token))throw new Error('Configure a public HTTPS gateway, site ID and server-only token.');
  const origin=gateway.replace(/\/$/,'');
  async function request(path,method='GET',body=null) {
    const response=await fetchImpl(origin+'/api/v1/sites/'+siteId+path,{method,headers:{Authorization:'Bearer '+token,Accept:'application/json',...(body!==null?{'Content-Type':'application/json'}:{})},body:body!==null?JSON.stringify(body):null,cache:'no-store',redirect:'error',signal:AbortSignal.timeout(10000)});
    const text=await response.text();if(Buffer.byteLength(text)>1048576)throw new Error('LifeOS response exceeded its limit.');let data;try{data=JSON.parse(text);}catch{throw new Error('Invalid LifeOS JSON response.');}if(!response.ok)throw new Error('LifeOS request failed (HTTP '+response.status+').');return data;
  }
  const slug=value=>{if(!/^[a-z][a-z0-9_-]{0,63}$/.test(value))throw new Error('Invalid page slug.');return value;};
  return {
    manifest:()=>request('/manifest'),
    getPage:page=>request('/pages/'+slug(page)),
    register:(manifest,initial={})=>request('/register','POST',{manifest,initial}),
    async verifyWebhook(rawBody,headers,{now=Math.floor(Date.now()/1000)}={}) {
      if(typeof rawBody!=='string'||Buffer.byteLength(rawBody)>65536||typeof webhookSecret!=='string'||webhookSecret.length<32)throw new Error('Invalid webhook payload or configuration.');
      const timestamp=typeof headers.get==='function'?headers.get('X-LifeOS-Timestamp'):headers['x-lifeos-timestamp'];
      const signature=typeof headers.get==='function'?headers.get('X-LifeOS-Signature'):headers['x-lifeos-signature'];
      if(!/^\d{10,12}$/.test(timestamp||'')||Math.abs(now-Number(timestamp))>300||Number(timestamp)>now+30||!/^sha256=[a-f0-9]{64}$/.test(signature||''))throw new Error('Expired or invalid webhook.');
      const expected='sha256='+createHmac('sha256',webhookSecret).update(timestamp+'.'+rawBody).digest('hex');
      if(!timingSafeEqual(Buffer.from(expected),Buffer.from(signature)))throw new Error('Webhook signature mismatch.');
      const event=JSON.parse(rawBody);
      if(event.event!=='content.published'||event.site_id!==siteId||!/^\w[\w-]{0,63}$/.test(event.page)||!Number.isInteger(event.version)||event.version<0||!/^[a-f0-9]{32}$/.test(event.deployment_id))throw new Error('Webhook site or event mismatch.');
      const result=await request('/webhooks/claim','POST',{deployment_id:event.deployment_id});return {...event,duplicate:!result.claimed};
    },
    releaseWebhook:event=>request('/webhooks/release','POST',{deployment_id:event.deployment_id}),
    reportDeployment:(event,status)=>{if(!['building','live','failed'].includes(status))throw new Error('Invalid deployment status.');return request('/deployments/'+event.deployment_id+'/status','POST',{status});},
  };
}

/** Add this escaped marker to rendered HTML so Gateway can verify the live version. */
export function versionMarker(page) {
  if(!/^[a-z][a-z0-9_-]{0,63}$/.test(page.slug)||!Number.isInteger(page.version)||page.version<0)throw new Error('Invalid content version.');
  return 'lifeos-version:'+page.slug+':'+page.version;
}
