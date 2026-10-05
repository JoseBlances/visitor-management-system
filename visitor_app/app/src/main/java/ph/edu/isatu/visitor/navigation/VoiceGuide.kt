package ph.edu.isatu.visitor.navigation

import android.content.Context
import android.media.AudioAttributes
import android.media.AudioFocusRequest
import android.media.AudioManager
import android.speech.tts.TextToSpeech
import android.speech.tts.UtteranceProgressListener
import java.util.Locale
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * Speaks walking directions with the phone's text-to-speech engine, as navigation audio:
 * music is lowered briefly instead of paused. Call [shutdown] when the screen closes.
 */
class VoiceGuide(context: Context) {
    enum class Status { STARTING, READY, UNAVAILABLE }

    private val audioManager = context.applicationContext.getSystemService(AudioManager::class.java)
    private val attributes = AudioAttributes.Builder()
        .setUsage(AudioAttributes.USAGE_ASSISTANCE_NAVIGATION_GUIDANCE)
        .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH)
        .build()
    private var focusRequest: AudioFocusRequest? = null
    private var utteranceCount = 0

    private val _status = MutableStateFlow(Status.STARTING)
    val status: StateFlow<Status> = _status.asStateFlow()

    private var engine: TextToSpeech? = null
    private var earlyInitResult: Int? = null

    init {
        // Some engines report their start-up result before the constructor returns.
        val tts = TextToSpeech(context.applicationContext) { result ->
            val created = engine
            if (created == null) earlyInitResult = result else onEngineReady(created, result)
        }
        engine = tts
        earlyInitResult?.let { onEngineReady(tts, it) }
    }

    private fun onEngineReady(tts: TextToSpeech, result: Int) {
        if (engine !== tts || result != TextToSpeech.SUCCESS) {
            _status.value = Status.UNAVAILABLE
            return
        }
        val locale = listOf(Locale.US, Locale.UK, Locale.ENGLISH)
            .firstOrNull { tts.isLanguageAvailable(it) >= TextToSpeech.LANG_AVAILABLE }
        if (locale == null || tts.setLanguage(locale) < TextToSpeech.LANG_AVAILABLE) {
            _status.value = Status.UNAVAILABLE
            return
        }
        tts.setAudioAttributes(attributes)
        tts.setOnUtteranceProgressListener(object : UtteranceProgressListener() {
            override fun onStart(utteranceId: String?) = Unit
            override fun onDone(utteranceId: String?) = releaseFocusWhenQuiet()

            @Deprecated("Deprecated in Java")
            override fun onError(utteranceId: String?) = releaseFocusWhenQuiet()

            override fun onError(utteranceId: String?, errorCode: Int) = releaseFocusWhenQuiet()
        })
        _status.value = Status.READY
    }

    /** Speaks [text]; an [urgent] prompt replaces anything still being said. */
    fun speak(text: String, urgent: Boolean = false) {
        val tts = engine ?: return
        if (_status.value != Status.READY) return
        requestFocus()
        tts.speak(text, if (urgent) TextToSpeech.QUEUE_FLUSH else TextToSpeech.QUEUE_ADD, null, "isatu-${++utteranceCount}")
    }

    /** True when media volume is muted, so spoken directions would not be heard. */
    fun volumeMuted(): Boolean = audioManager?.getStreamVolume(AudioManager.STREAM_MUSIC) == 0

    fun silence() {
        engine?.stop()
        releaseFocus()
    }

    fun shutdown() {
        engine?.stop()
        engine?.shutdown()
        engine = null
        releaseFocus()
        _status.value = Status.UNAVAILABLE
    }

    @Synchronized
    private fun requestFocus() {
        if (focusRequest != null || audioManager == null) return
        val request = AudioFocusRequest.Builder(AudioManager.AUDIOFOCUS_GAIN_TRANSIENT_MAY_DUCK)
            .setAudioAttributes(attributes)
            .build()
        if (audioManager.requestAudioFocus(request) == AudioManager.AUDIOFOCUS_REQUEST_GRANTED) {
            focusRequest = request
        }
    }

    private fun releaseFocusWhenQuiet() {
        if (engine?.isSpeaking != true) releaseFocus()
    }

    @Synchronized
    private fun releaseFocus() {
        focusRequest?.let { audioManager?.abandonAudioFocusRequest(it) }
        focusRequest = null
    }
}
