package ph.edu.isatu.visitor.navigation

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class GeoMathTest {
    private val origin = GeoPoint(10.7177, 122.5559)
    private val square = listOf(
        GeoPoint(10.7167, 122.5549),
        GeoPoint(10.7167, 122.5569),
        GeoPoint(10.7187, 122.5569),
        GeoPoint(10.7187, 122.5549),
    )

    @Test
    fun oneThousandthOfADegreeNorthIsAbout111Meters() {
        assertEquals(111.19, GeoMath.distanceMeters(origin, GeoPoint(10.7187, 122.5559)), 0.5)
        assertEquals(0.0, GeoMath.distanceMeters(origin, origin), 0.0001)
    }

    @Test
    fun bearingsPointTheRightWay() {
        assertEquals(0.0, GeoMath.bearingDegrees(origin, GeoPoint(10.7187, 122.5559)), 0.1)
        assertEquals(90.0, GeoMath.bearingDegrees(origin, GeoPoint(10.7177, 122.5569)), 0.1)
        assertEquals(180.0, GeoMath.bearingDegrees(origin, GeoPoint(10.7167, 122.5559)), 0.1)
        assertEquals(270.0, GeoMath.bearingDegrees(origin, GeoPoint(10.7177, 122.5549)), 0.1)
    }

    @Test
    fun signedDifferenceTakesTheShortWayRound() {
        assertEquals(20.0, GeoMath.signedDifference(10.0, 350.0), 0.0001)
        assertEquals(-20.0, GeoMath.signedDifference(350.0, 10.0), 0.0001)
        assertEquals(180.0, GeoMath.signedDifference(180.0, 0.0), 0.0001)
        assertEquals(0.0, GeoMath.signedDifference(725.0, 5.0), 0.0001)
    }

    @Test
    fun containsTellsInsideFromOutside() {
        assertTrue(GeoMath.contains(square, origin))
        assertFalse(GeoMath.contains(square, GeoPoint(10.7190, 122.5559)))
        assertFalse(GeoMath.contains(square, GeoPoint(10.7177, 122.5600)))
    }

    @Test
    fun distanceToBoundaryMatchesTheServersFlatProjection() {
        // 0.0001° north of the top edge is 11.13 m with the server's 111,320 m per degree.
        assertEquals(11.13, GeoMath.distanceToBoundaryMeters(square, GeoPoint(10.7188, 122.5559)), 0.05)
    }

    @Test
    fun insideCampusAllowsTheGpsBuffer() {
        val twentyMetersOut = GeoPoint(10.7187 + 20 / 111_320.0, 122.5559)
        assertTrue(GeoMath.insideCampus(square, twentyMetersOut, 25.0))
        assertFalse(GeoMath.insideCampus(square, twentyMetersOut, 10.0))
        // Without a drawn boundary, nothing counts as outside.
        assertTrue(GeoMath.insideCampus(emptyList(), twentyMetersOut, 0.0))
    }
}
