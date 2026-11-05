(() => {
  'use strict';
  const bound = new WeakMap(), live = new Set(), maxSeconds = 180, sampleRate = 16000;
  // PCM WAV is produced in the browser; no audio, encoder or model is downloaded.
  async function wavFromRecording(recording) {
    const context = new AudioContext();
    let decoded;
    try { decoded = await context.decodeAudioData(await recording.arrayBuffer()); }
    finally { await context.close(); }
    if (!Number.isFinite(decoded.duration) || decoded.duration < .2) throw new Error('short');
    const frames = Math.min(Math.floor(decoded.duration * sampleRate), maxSeconds * sampleRate);
    const offline = new OfflineAudioContext(1, frames, sampleRate), source = offline.createBufferSource();
    source.buffer = decoded; source.connect(offline.destination); source.start();
    const audio = (await offline.startRendering()).getChannelData(0);
    const buffer = new ArrayBuffer(44 + audio.length * 2), view = new DataView(buffer);
    const word = (offset, value) => [...value].forEach((letter, i) => view.setUint8(offset + i, letter.charCodeAt(0)));
    word(0, 'RIFF'); view.setUint32(4, buffer.byteLength - 8, true); word(8, 'WAVE'); word(12, 'fmt ');
    view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true);
    view.setUint32(24, sampleRate, true); view.setUint32(28, sampleRate * 2, true);
    view.setUint16(32, 2, true); view.setUint16(34, 16, true); word(36, 'data'); view.setUint32(40, audio.length * 2, true);
    for (let i = 0; i < audio.length; i++) {
      const value = Math.max(-1, Math.min(1, audio[i])); view.setInt16(44 + i * 2, value < 0 ? value * 32768 : value * 32767, true);
    }
    return new Blob([buffer], { type: 'audio/wav' });
  }
  function bind(panel) {
    if (bound.has(panel)) return;
    const find = name => panel.querySelector(`[data-voice-${name}]`), messages = JSON.parse(panel.dataset.voiceMessages || '{}');
    const t = value => messages[value] || value;
    const start = find('start'), stop = find('stop'), cancel = find('cancel'), transcribe = find('transcribe');
    const status = find('status'), clock = find('clock'), playback = find('playback'), review = find('review'), download = find('download');
    let state = 'idle', epoch = 0, recorder, stream, timer, deadline, started = 0, audio, url;
    function change(next, message) {
      state = next; panel.dataset.state = next; status.textContent = t(message);
      panel.setAttribute('aria-busy', ['requesting', 'converting', 'transcribing'].includes(next) ? 'true' : 'false');
      start.hidden = ['requesting', 'recording', 'converting', 'transcribing'].includes(next);
      start.textContent = t(next === 'ready' ? 'Record again' : 'Start recording');
      stop.hidden = next !== 'recording'; cancel.hidden = !['requesting', 'recording', 'converting', 'ready'].includes(next);
      review.hidden = !audio; clock.hidden = next !== 'recording';
      transcribe.disabled = next !== 'ready' || panel.dataset.voiceReady !== '1';
      download.hidden = next === 'transcribing';
    }
    function releaseCapture() {
      clearInterval(timer); clearTimeout(deadline);
      if (recorder?.state === 'recording') recorder.stop();
      for (const track of stream?.getTracks() || []) track.stop();
      stream = null;
    }
    function discard(message = 'Recording discarded.') {
      ++epoch; releaseCapture();
      if (recorder) recorder.ondataavailable = recorder.onstop = recorder.onerror = null;
      recorder = null; audio = null;
      playback.pause(); playback.removeAttribute('src'); playback.load();
      if (url) URL.revokeObjectURL(url); url = null; download.removeAttribute('href');
      change('idle', message);
    }
    function stopCapture(message = 'Preparing your recording…') {
      if (state !== 'recording') return;
      change('converting', message); releaseCapture();
    }
    async function begin() {
      if (['requesting', 'recording', 'converting', 'transcribing'].includes(state)) return;
      discard('Allow microphone access to start recording.');
      if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder || !window.AudioContext || !window.OfflineAudioContext) {
        change('error', 'Recording is unavailable here. You can upload a WAV voice note below.'); return;
      }
      const attempt = epoch;
      change('requesting', 'Allow microphone access to start recording.');
      try {
        // Called directly from the button gesture so the native host can authorize this request only.
        const permission = navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true }, video: false });
        const granted = await permission;
        if (attempt !== epoch || !panel.isConnected) { granted.getTracks().forEach(track => track.stop()); return; }
        stream = granted;
        const mime = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'].find(type => MediaRecorder.isTypeSupported(type));
        recorder = new MediaRecorder(stream, mime ? { mimeType: mime, audioBitsPerSecond: 64000 } : {});
        const chunks = []; let bytes = 0;
        recorder.ondataavailable = event => {
          if (attempt !== epoch || !event.data.size) return;
          bytes += event.data.size;
          if (bytes > 20 * 1024 * 1024) { discard('Recording exceeded the size limit. Try a shorter note.'); return; }
          chunks.push(event.data);
        };
        recorder.onerror = () => { if (attempt === epoch) { discard(); change('error', 'The microphone stopped unexpectedly. Try recording again.'); } };
        recorder.onstop = async () => {
          if (attempt !== epoch) return;
          try {
            const wav = await wavFromRecording(new Blob(chunks, { type: recorder.mimeType }));
            if (attempt !== epoch || !panel.isConnected) return;
            audio = wav; url = URL.createObjectURL(wav); playback.src = url; download.href = url;
            change('ready', 'Listen to your recording, then choose Transcribe locally.');
            transcribe.disabled ? download.focus() : transcribe.focus();
          } catch (_) { if (attempt === epoch) change('error', 'Could not prepare this recording. Record at least one second and try again.'); }
        };
        for (const track of stream.getTracks()) track.addEventListener('ended', () => stopCapture());
        recorder.start(1000); started = performance.now();
        clock.textContent = '0:00 / 3:00'; change('recording', 'Recording…');
        timer = setInterval(() => {
          const seconds = Math.min(maxSeconds, Math.floor((performance.now() - started) / 1000));
          clock.textContent = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')} / 3:00`;
        }, 250);
        deadline = setTimeout(() => stopCapture(), maxSeconds * 1000); stop.focus();
      } catch (error) {
        if (attempt !== epoch) return;
        releaseCapture();
        change('error', error.name === 'NotAllowedError' || error.name === 'SecurityError' ?
          'Microphone access was not allowed. Check Windows or browser microphone permissions, or upload a WAV note.' :
          'No microphone is available. Connect one, or upload a WAV voice note below.');
      }
    }
    async function send() {
      if (state !== 'ready' || !audio || panel.dataset.voiceReady !== '1') return;
      const attempt = epoch, body = new FormData(), controller = new AbortController();
      body.append('_csrf', find('csrf').value); body.append('media', audio, 'LifeOS-voice-note.wav');
      change('transcribing', 'Transcribing on this PC… This can take up to a minute.');
      const timeout = setTimeout(() => controller.abort(), 70000);
      try {
        const response = await fetch('/intelligence/voice', { method: 'POST', body, signal: controller.signal, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const result = response.headers.get('Content-Type')?.includes('application/json') ? await response.json() : {};
        if (attempt !== epoch || !panel.isConnected) return;
        if (!response.ok || !/^\/intelligence\?source=\d+$/.test(result.review || '')) throw new Error('transcription');
        discard('Local text is ready for review.'); window.location.assign(result.review);
      } catch (_) {
        if (attempt === epoch && panel.isConnected) change('ready', 'Could not transcribe this recording. Check the installed voice tool and model, then try again. Your recording is still here.');
      } finally { clearTimeout(timeout); }
    }
    const session = {
      pause: () => { if (state === 'requesting') discard('Recording cancelled while LifeOS was hidden.'); else stopCapture(); },
      dispose: () => { discard(); live.delete(session); }
    };
    bound.set(panel, session); live.add(session);
    start.addEventListener('click', begin); stop.addEventListener('click', () => stopCapture()); cancel.addEventListener('click', () => { discard(); start.focus(); });
    transcribe.addEventListener('click', send);
    panel.addEventListener('keydown', event => { if (event.key === 'Escape' && ['requesting', 'recording', 'converting', 'ready'].includes(state)) { event.preventDefault(); discard(); start.focus(); } });
  }
  const init = () => document.querySelectorAll('[data-voice-recorder]').forEach(bind);
  document.addEventListener('DOMContentLoaded', init); document.addEventListener('htmx:afterSettle', init);
  document.addEventListener('htmx:beforeCleanupElement', event => {
    const element = event.detail.elt;
    for (const panel of element.matches?.('[data-voice-recorder]') ? [element] : element.querySelectorAll?.('[data-voice-recorder]') || []) bound.get(panel)?.dispose();
  });
  document.addEventListener('visibilitychange', () => { if (document.hidden) for (const session of live) session.pause(); });
  window.addEventListener('blur', () => { for (const session of live) session.pause(); });
  window.addEventListener('pagehide', () => { for (const session of [...live]) session.dispose(); });
  window.chrome?.webview?.addEventListener('message', event => { if (event.data?.type === 'lifeos-voice-stop') for (const session of live) session.pause(); });
  init();
})();
