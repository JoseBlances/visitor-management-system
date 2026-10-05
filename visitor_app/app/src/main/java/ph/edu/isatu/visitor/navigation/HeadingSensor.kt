package ph.edu.isatu.visitor.navigation

import android.content.Context
import android.hardware.GeomagneticField
import android.hardware.Sensor
import android.hardware.SensorEvent
import android.hardware.SensorEventListener
import android.hardware.SensorManager
import java.util.concurrent.CopyOnWriteArrayList
import kotlin.math.abs
import kotlin.math.atan2
import kotlin.math.cos
import kotlin.math.sin
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * The direction the visitor is facing, from the phone's rotation sensor, in degrees from
 * true north. Works with the phone held flat (the top edge points the way) or upright (the
 * back of the phone points the way). Readings are smoothed so the arrow and map don't
 * shake. Call [start] and [stop] with the screen's lifecycle.
 */
class HeadingSensor(context: Context) : SensorEventListener {
    private val sensorManager = context.getSystemService(SensorManager::class.java)
    private val sensor: Sensor? = sensorManager?.getDefaultSensor(Sensor.TYPE_ROTATION_VECTOR)
        ?: sensorManager?.getDefaultSensor(Sensor.TYPE_GEOMAGNETIC_ROTATION_VECTOR)
    private val rotation = FloatArray(9)
    private val remapped = FloatArray(9)
    private val orientation = FloatArray(3)
    private val headingListeners = CopyOnWriteArrayList<(Float) -> Unit>()
    private val accuracyListeners = CopyOnWriteArrayList<(Int) -> Unit>()
    private var running = false
    private var upright = false
    private var smoothSin = 0.0
    private var smoothCos = 1.0
    private var smoothed = false
    @Volatile private var declination = 0f

    private val _heading = MutableStateFlow<Float?>(null)
    /** Degrees from true north, or null before the first reading. */
    val heading: StateFlow<Float?> = _heading.asStateFlow()

    private val _needsCalibration = MutableStateFlow(false)
    /** True when Android reports the compass as unreliable (it needs a figure-8 wave). */
    val needsCalibration: StateFlow<Boolean> = _needsCalibration.asStateFlow()

    @Volatile var accuracyStatus: Int = SensorManager.SENSOR_STATUS_ACCURACY_MEDIUM
        private set

    val available: Boolean get() = sensor != null

    fun start() {
        if (running || sensor == null) return
        running = sensorManager?.registerListener(this, sensor, SensorManager.SENSOR_DELAY_UI) == true
    }

    fun stop() {
        if (!running) return
        sensorManager?.unregisterListener(this)
        running = false
    }

    /** Converts magnetic north to true north for the visitor's location. */
    fun updateDeclination(point: GeoPoint) {
        declination = GeomagneticField(
            point.latitude.toFloat(),
            point.longitude.toFloat(),
            0f,
            System.currentTimeMillis(),
        ).declination
    }

    fun addListener(onHeading: (Float) -> Unit, onAccuracy: (Int) -> Unit) {
        headingListeners += onHeading
        accuracyListeners += onAccuracy
    }

    fun removeListener(onHeading: (Float) -> Unit, onAccuracy: (Int) -> Unit) {
        headingListeners -= onHeading
        accuracyListeners -= onAccuracy
    }

    override fun onSensorChanged(event: SensorEvent) {
        SensorManager.getRotationMatrixFromVector(rotation, event.values)
        // rotation[8] is 1 with the screen facing up and 0 with the phone held upright.
        // Switch between the two ways of reading the heading with some hysteresis.
        val faceUp = abs(rotation[8])
        upright = if (upright) faceUp < UPRIGHT_EXIT else faceUp < UPRIGHT_ENTER
        val matrix = if (upright) {
            // Read the direction the back of the phone faces instead of its top edge.
            SensorManager.remapCoordinateSystem(rotation, SensorManager.AXIS_X, SensorManager.AXIS_Z, remapped)
            remapped
        } else {
            rotation
        }
        SensorManager.getOrientation(matrix, orientation)
        val radians = orientation[0] + Math.toRadians(declination.toDouble())
        // Smooth on the unit circle, so 359° to 1° never swings through 180°.
        if (!smoothed) {
            smoothSin = sin(radians)
            smoothCos = cos(radians)
            smoothed = true
        } else {
            smoothSin += SMOOTHING * (sin(radians) - smoothSin)
            smoothCos += SMOOTHING * (cos(radians) - smoothCos)
        }
        val degrees = GeoMath.normalizeDegrees(Math.toDegrees(atan2(smoothSin, smoothCos))).toFloat()
        _heading.value = degrees
        headingListeners.forEach { it(degrees) }
    }

    override fun onAccuracyChanged(sensor: Sensor, accuracy: Int) {
        accuracyStatus = accuracy
        _needsCalibration.value = accuracy == SensorManager.SENSOR_STATUS_UNRELIABLE
        accuracyListeners.forEach { it(accuracy) }
    }

    private companion object {
        const val SMOOTHING = 0.2
        /** About 50° from flat: start reading the heading from the back of the phone. */
        const val UPRIGHT_ENTER = 0.643f
        /** About 40° from flat: go back to the top edge. */
        const val UPRIGHT_EXIT = 0.766f
    }
}
