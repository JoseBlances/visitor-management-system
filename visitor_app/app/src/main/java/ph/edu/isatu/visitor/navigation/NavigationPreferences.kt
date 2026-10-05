package ph.edu.isatu.visitor.navigation

import android.content.Context
import androidx.core.content.edit

/**
 * The visitor's choices for the campus map, plus where they checked in for each visit
 * (used to guide them back when no gate is set up on the map). Stays on the phone.
 */
class NavigationPreferences(context: Context) {
    private val preferences = context.getSharedPreferences("visitor_navigation", Context.MODE_PRIVATE)

    var voiceEnabled: Boolean
        get() = preferences.getBoolean(KEY_VOICE, true)
        set(value) = preferences.edit { putBoolean(KEY_VOICE, value) }

    var unit: DistanceUnit
        get() = if (preferences.getString(KEY_UNIT, null) == DistanceUnit.FEET.name) DistanceUnit.FEET else DistanceUnit.METERS
        set(value) = preferences.edit { putString(KEY_UNIT, value.name) }

    var keepScreenOn: Boolean
        get() = preferences.getBoolean(KEY_SCREEN_ON, true)
        set(value) = preferences.edit { putBoolean(KEY_SCREEN_ON, value) }

    fun entryPoint(appointmentId: Long): GeoPoint? {
        if (preferences.getLong(KEY_ENTRY_APPOINTMENT, 0L) != appointmentId) return null
        val latitude = preferences.getString(KEY_ENTRY_LATITUDE, null)?.toDoubleOrNull() ?: return null
        val longitude = preferences.getString(KEY_ENTRY_LONGITUDE, null)?.toDoubleOrNull() ?: return null
        return GeoPoint(latitude, longitude)
    }

    /** Only the latest visit's entry point is kept. */
    fun saveEntryPoint(appointmentId: Long, point: GeoPoint) = preferences.edit {
        putLong(KEY_ENTRY_APPOINTMENT, appointmentId)
        putString(KEY_ENTRY_LATITUDE, point.latitude.toString())
        putString(KEY_ENTRY_LONGITUDE, point.longitude.toString())
    }

    private companion object {
        const val KEY_VOICE = "voice_enabled"
        const val KEY_UNIT = "distance_unit"
        const val KEY_SCREEN_ON = "keep_screen_on"
        const val KEY_ENTRY_APPOINTMENT = "entry_appointment_id"
        const val KEY_ENTRY_LATITUDE = "entry_latitude"
        const val KEY_ENTRY_LONGITUDE = "entry_longitude"
    }
}
