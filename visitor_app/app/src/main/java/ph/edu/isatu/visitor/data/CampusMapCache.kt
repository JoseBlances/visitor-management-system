package ph.edu.isatu.visitor.data

import android.content.Context
import androidx.core.content.edit
import com.google.gson.Gson

/**
 * The last campus map the server sent for the visit in progress, so the visitor's map
 * keeps its boundary and office pins when the phone briefly loses its connection.
 * Only one visit is kept; signing out clears it.
 */
class CampusMapCache(context: Context, private val gson: Gson) {
    private val preferences = context.getSharedPreferences("campus_map_cache", Context.MODE_PRIVATE)

    fun save(map: CampusMapData) {
        preferences.edit {
            putLong(KEY_APPOINTMENT, map.appointmentId)
            putString(KEY_JSON, gson.toJson(map))
        }
    }

    fun load(appointmentId: Long): CampusMapData? {
        if (appointmentId <= 0 || preferences.getLong(KEY_APPOINTMENT, 0L) != appointmentId) return null
        val json = preferences.getString(KEY_JSON, null) ?: return null
        return runCatching { gson.fromJson(json, CampusMapData::class.java) }.getOrNull()
    }

    fun clear() = preferences.edit { clear() }

    private companion object {
        const val KEY_APPOINTMENT = "appointment_id"
        const val KEY_JSON = "campus_map_json"
    }
}
