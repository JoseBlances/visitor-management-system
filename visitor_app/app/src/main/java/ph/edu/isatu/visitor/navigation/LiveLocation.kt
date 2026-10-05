package ph.edu.isatu.visitor.navigation

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import android.os.Looper
import android.os.SystemClock
import androidx.core.content.ContextCompat
import androidx.core.location.LocationManagerCompat
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * Second-by-second position for the map screen only, so the arrow moves smoothly and
 * directions react quickly. It stays on the phone: Security still receives only the
 * background service's regular uploads. Call [start] and [stop] with the screen's lifecycle.
 */
class LiveLocation(context: Context) {
    private val appContext = context.applicationContext
    private val client = LocationServices.getFusedLocationProviderClient(appContext)
    private val locationManager = appContext.getSystemService(LocationManager::class.java)
    private var callback: LocationCallback? = null

    private val _location = MutableStateFlow<Location?>(null)
    val location: StateFlow<Location?> = _location.asStateFlow()

    /** Starts updates; false when precise location permission is missing. */
    fun start(): Boolean {
        if (callback != null) return true
        if (ContextCompat.checkSelfPermission(appContext, Manifest.permission.ACCESS_FINE_LOCATION) !=
            PackageManager.PERMISSION_GRANTED
        ) {
            return false
        }
        val request = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, INTERVAL_MILLIS)
            .setMinUpdateIntervalMillis(INTERVAL_MILLIS / 2)
            .setWaitForAccurateLocation(false)
            .build()
        val updates = object : LocationCallback() {
            override fun onLocationResult(result: LocationResult) {
                result.lastLocation?.let { _location.value = it }
            }
        }
        callback = updates
        client.requestLocationUpdates(request, updates, Looper.getMainLooper())
        // Show a recent position at once while the first fresh fix arrives.
        client.lastLocation.addOnSuccessListener { last ->
            if (last != null && _location.value == null && ageMillis(last) <= SEED_MAX_AGE_MILLIS) {
                _location.value = last
            }
        }
        return true
    }

    fun stop() {
        callback?.let { client.removeLocationUpdates(it) }
        callback = null
    }

    /** Whether the phone's Location setting is on at all. */
    fun servicesEnabled(): Boolean = locationManager?.let(LocationManagerCompat::isLocationEnabled) ?: false

    private fun ageMillis(location: Location): Long =
        (SystemClock.elapsedRealtimeNanos() - location.elapsedRealtimeNanos) / 1_000_000

    private companion object {
        const val INTERVAL_MILLIS = 1_000L
        const val SEED_MAX_AGE_MILLIS = 120_000L
    }
}
