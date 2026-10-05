package ph.edu.isatu.visitor.navigation

import kotlin.math.PI
import kotlin.math.asin
import kotlin.math.atan2
import kotlin.math.cos
import kotlin.math.hypot
import kotlin.math.min
import kotlin.math.sin
import kotlin.math.sqrt

data class GeoPoint(val latitude: Double, val longitude: Double)

/**
 * Distances and directions on the campus. The boundary checks mirror the server's
 * campus_map_service.php exactly, so the app warns about leaving the campus on the same
 * rule the server uses to end a visit.
 */
object GeoMath {
    private const val EARTH_RADIUS_METERS = 6_371_000.0
    private const val METERS_PER_DEGREE = 111_320.0
    private const val TO_RADIANS = PI / 180

    /** Great-circle distance in meters. */
    fun distanceMeters(a: GeoPoint, b: GeoPoint): Double {
        val dLat = (b.latitude - a.latitude) * TO_RADIANS
        val dLng = (b.longitude - a.longitude) * TO_RADIANS
        val h = sin(dLat / 2).let { it * it } +
            cos(a.latitude * TO_RADIANS) * cos(b.latitude * TO_RADIANS) * sin(dLng / 2).let { it * it }
        return 2 * EARTH_RADIUS_METERS * asin(min(1.0, sqrt(h)))
    }

    /** Compass direction from [from] to [to]: 0 is north, 90 east. */
    fun bearingDegrees(from: GeoPoint, to: GeoPoint): Double {
        val lat1 = from.latitude * TO_RADIANS
        val lat2 = to.latitude * TO_RADIANS
        val dLng = (to.longitude - from.longitude) * TO_RADIANS
        val y = sin(dLng) * cos(lat2)
        val x = cos(lat1) * sin(lat2) - sin(lat1) * cos(lat2) * cos(dLng)
        return normalizeDegrees(atan2(y, x) / TO_RADIANS)
    }

    fun normalizeDegrees(degrees: Double): Double = ((degrees % 360) + 360) % 360

    /** How far [target] is turned from [reference], from -180 (left) to 180 (right). */
    fun signedDifference(target: Double, reference: Double): Double {
        val difference = normalizeDegrees(target - reference)
        return if (difference > 180) difference - 360 else difference
    }

    /** Ray-casting point-in-polygon test, as the server does it. */
    fun contains(boundary: List<GeoPoint>, point: GeoPoint): Boolean {
        var inside = false
        var j = boundary.size - 1
        for (i in boundary.indices) {
            val a = boundary[i]
            val b = boundary[j]
            if ((a.latitude > point.latitude) != (b.latitude > point.latitude) &&
                point.longitude < (b.longitude - a.longitude) * (point.latitude - a.latitude) /
                (b.latitude - a.latitude) + a.longitude
            ) {
                inside = !inside
            }
            j = i
        }
        return inside
    }

    /** Shortest distance in meters from [point] to the boundary outline. */
    fun distanceToBoundaryMeters(boundary: List<GeoPoint>, point: GeoPoint): Double {
        val metersPerLng = METERS_PER_DEGREE * cos(point.latitude * TO_RADIANS)
        var nearest = Double.POSITIVE_INFINITY
        var j = boundary.size - 1
        for (i in boundary.indices) {
            val ax = (boundary[j].longitude - point.longitude) * metersPerLng
            val ay = (boundary[j].latitude - point.latitude) * METERS_PER_DEGREE
            val bx = (boundary[i].longitude - point.longitude) * metersPerLng
            val by = (boundary[i].latitude - point.latitude) * METERS_PER_DEGREE
            val dx = bx - ax
            val dy = by - ay
            val lengthSquared = dx * dx + dy * dy
            val t = if (lengthSquared > 0) (-(ax * dx + ay * dy) / lengthSquared).coerceIn(0.0, 1.0) else 0.0
            nearest = min(nearest, hypot(ax + t * dx, ay + t * dy))
            j = i
        }
        return nearest
    }

    /**
     * Inside the campus, counting up to [bufferMeters] past the line as inside, the same
     * allowance the server gives for GPS drift at gates and building edges.
     */
    fun insideCampus(boundary: List<GeoPoint>, point: GeoPoint, bufferMeters: Double): Boolean =
        boundary.size < 3 || contains(boundary, point) || distanceToBoundaryMeters(boundary, point) <= bufferMeters
}
