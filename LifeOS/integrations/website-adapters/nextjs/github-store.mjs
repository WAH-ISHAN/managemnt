import { ConnectorError } from './connector.mjs';

/** Versioned content-as-code store: no ephemeral writes to a Vercel filesystem. */
export function createGitHubStore({owner,repo,path,branch='main',token=()=>process.env.LIFEOS_GITHUB_TOKEN,fetchImpl=fetch}) {
  if (![owner,repo].every(value=>/^[A-Za-z0-9_.-]+$/.test(value)) || !/^[A-Za-z0-9_./-]+\.json$/.test(path) || path.split('/').includes('..')) throw new Error('Invalid GitHub content configuration.');
  const endpoint=`https://api.github.com/repos/${owner}/${repo}/contents/${path.split('/').map(encodeURIComponent).join('/')}`;
  const headers=()=>{
    const secret=token();
    if (!secret || /[\r\n]/.test(secret)) throw new ConnectorError(503,'Configure the website content store.');
    return {'Authorization':`Bearer ${secret}`,'Accept':'application/vnd.github+json','X-GitHub-Api-Version':'2026-03-10','Content-Type':'application/json'};
  };
  return {
    async read() {
      const response=await fetchImpl(endpoint+'?ref='+encodeURIComponent(branch),{headers:headers(),cache:'no-store',redirect:'error',signal:AbortSignal.timeout(10000)});
      if (!response.ok) throw new ConnectorError(503,'Website content could not be loaded.');
      const file=await response.json();
      if (file.encoding!=='base64' || typeof file.content!=='string' || file.content.length>750000 || typeof file.sha!=='string') throw new ConnectorError(503,'Invalid website content response.');
      return {document:JSON.parse(Buffer.from(file.content,'base64').toString('utf8')),revision:file.sha};
    },
    async write(document,revision,message) {
      const response=await fetchImpl(endpoint,{method:'PUT',headers:headers(),redirect:'error',signal:AbortSignal.timeout(15000),body:JSON.stringify({message,content:Buffer.from(JSON.stringify(document,null,2)+'\n').toString('base64'),sha:revision,branch})});
      if (response.status===409 || response.status===422) throw new ConnectorError(409,'The website changed. Refresh before saving again.');
      if (!response.ok) throw new ConnectorError(503,'Website content could not be saved.');
    }
  };
}
