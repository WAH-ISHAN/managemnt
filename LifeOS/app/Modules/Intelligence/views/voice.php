<?php
$voiceReady=is_file(Db::setting('intelligence_whisper')) && is_file(Db::setting('intelligence_whisper_model'));
$voiceStrings=['Record again','Start recording','Recording discarded.','Preparing your recording…','Allow microphone access to start recording.','Recording is unavailable here. You can upload a WAV voice note below.','Recording exceeded the size limit. Try a shorter note.','The microphone stopped unexpectedly. Try recording again.','Listen to your recording, then choose Transcribe locally.','Could not prepare this recording. Record at least one second and try again.','Recording…','Microphone access was not allowed. Check Windows or browser microphone permissions, or upload a WAV note.','No microphone is available. Connect one, or upload a WAV voice note below.','Transcribing on this PC… This can take up to a minute.','Local text is ready for review.','Could not transcribe this recording. Check the installed voice tool and model, then try again. Your recording is still here.','Recording cancelled while LifeOS was hidden.'];
$voiceMessages=array_combine($voiceStrings,array_map('t',$voiceStrings));
?>
<section class="card voice-recorder" data-voice-recorder data-voice-ready="<?= $voiceReady ? '1' : '0' ?>" data-voice-messages="<?= e(json_encode($voiceMessages,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)) ?>" hx-boost="false" aria-labelledby="voice-heading">
<div class="section-heading"><h2 id="voice-heading"><?= e(t('Record a voice note')) ?></h2><span class="badge"><?= e(t('Up to 3 minutes')) ?></span></div>
<p class="muted"><?= e(t('Audio stays on this PC. Listen before transcribing, then review the text before creating tasks.')) ?></p>
<input type="hidden" data-voice-csrf value="<?= e($_SESSION['csrf']) ?>">
<p class="voice-status" data-voice-status role="status" aria-live="polite"><?= e(t('Ready when you are.')) ?></p>
<p class="voice-clock" data-voice-clock hidden aria-label="<?= e(t('Recording duration')) ?>">0:00 / 3:00</p>
<div class="voice-actions">
<button type="button" data-voice-start><?= e(t('Start recording')) ?></button>
<button type="button" class="secondary" data-voice-stop hidden><?= e(t('Stop recording')) ?></button>
<button type="button" class="quiet" data-voice-cancel hidden><?= e(t('Discard recording')) ?></button>
</div>
<div class="voice-review" data-voice-review hidden>
<audio data-voice-playback controls preload="metadata" aria-label="<?= e(t('Listen to your recording')) ?>"></audio>
<div class="voice-actions"><button type="button" data-voice-transcribe <?= !$voiceReady ? 'disabled' : '' ?>><?= e(t('Transcribe locally')) ?></button><a class="btn secondary" data-voice-download download="LifeOS-voice-note.wav"><?= e(t('Save WAV')) ?></a></div>
<?php if (!$voiceReady): ?><p class="footnote"><?= e(t('Set up the installed voice tool before transcribing. Recording and saving audio are available now.')) ?> <a href="#local-tools"><?= e(t('Tools on this PC')) ?></a></p><?php endif; ?>
</div>
<p class="footnote"><?= e(t('The microphone turns off when you stop, leave this page, switch windows or hide LifeOS. Discard removes the recording from this page.')) ?></p>
<noscript><p><?= e(t('Enable JavaScript to record, or upload a WAV voice note below.')) ?></p></noscript>
</section>
