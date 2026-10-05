package ph.edu.isatu.visitor.navigation

import kotlin.math.PI
import kotlin.math.cos
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class WalkwayPlannerTest {
    private val gate = GeoPoint(10.7177, 122.5559)

    /** A point [north] and [east] meters from the gate. */
    private fun at(north: Double, east: Double = 0.0) = GeoPoint(
        gate.latitude + north / 111_194.93,
        gate.longitude + east / (111_194.93 * cos(gate.latitude * PI / 180)),
    )

    private fun walkway(id: Long, vararg points: Pair<Double, Double>) =
        Walkway(id, "OFFICE$id", "Route $id", points.map { (north, east) -> at(north, east) })

    /** Main Gate, 60 m north, then 40 m east to the office. */
    private val lShape = walkway(1, 0.0 to 0.0, 60.0 to 0.0, 60.0 to 40.0)

    @Test
    fun followsTheRecordedRouteAroundTheCorner() {
        val path = WalkwayPlanner(listOf(lShape)).plan(at(0.0), at(60.0, 40.0))!!
        assertEquals(100.0, path.lengthMeters, 0.5)
        assertEquals(0.0, path.joinMeters, 0.5)
        assertEquals(0.0, path.finishMeters, 0.5)
        assertEquals(1, path.turns.size)
        val turn = path.turns.single()
        assertEquals(TurnDirection.RIGHT, turn.direction)
        assertEquals(60.0, turn.atMeters, 1.0)
        assertTrue(GeoMath.distanceMeters(turn.point, at(60.0)) < 1.0)
        assertEquals(at(0.0), path.points.first())
        assertEquals(at(60.0, 40.0), path.target)
    }

    @Test
    fun joinsTheWalkwayStraightAcrossNotBackwards() {
        val path = WalkwayPlanner(listOf(lShape)).plan(at(30.0, -10.0), at(60.0, 40.0))!!
        assertEquals(10.0, path.joinMeters, 0.5)
        assertTrue("joins at the nearest point", GeoMath.distanceMeters(path.points[path.walkwayStart], at(30.0)) < 0.5)
        assertEquals(80.0, path.lengthMeters, 0.5)
    }

    @Test
    fun neverCutsThroughABuildingBetweenTwoWalkways() {
        // Two routes from the gate, on either side of a building 8 m apart: only the gate joins them.
        val west = walkway(1, 0.0 to 0.0, 0.0 to -4.0, 40.0 to -4.0)
        val east = walkway(2, 0.0 to 0.0, 0.0 to 4.0, 40.0 to 4.0)
        val path = WalkwayPlanner(listOf(west, east)).plan(at(40.0, -4.0), at(40.0, 4.0))!!
        assertTrue("goes back around through the gate, not 8 m across (${path.lengthMeters})", path.lengthMeters > 85)
        assertTrue("passes the gate", path.points.any { GeoMath.distanceMeters(it, at(0.0)) < 5 })
    }

    @Test
    fun treatsTwoRecordingsOfOneWalkwayAsTheSameWalkway() {
        // The same walkway recorded twice, 3 m apart; the second continues to another office.
        val first = walkway(1, 0.0 to 0.0, 50.0 to 0.0)
        val second = walkway(2, 0.0 to 3.0, 50.0 to 3.0, 50.0 to 30.0)
        val path = WalkwayPlanner(listOf(first, second)).plan(at(25.0, -1.0), at(50.0, 30.0))!!
        assertTrue("walks on, not back to the start (${path.lengthMeters})", path.lengthMeters < 60)
    }

    @Test
    fun turnsWhereTwoWalkwaysCross() {
        val eastWest = walkway(1, 0.0 to -30.0, 0.0 to 30.0)
        val northSouth = walkway(2, -30.0 to 0.0, 30.0 to 0.0)
        val path = WalkwayPlanner(listOf(eastWest, northSouth)).plan(at(0.0, -30.0), at(30.0, 0.0))!!
        assertEquals(60.0, path.lengthMeters, 3.0)
        assertEquals(TurnDirection.LEFT, path.turns.single().direction)
        assertEquals(30.0, path.turns.single().atMeters, 3.0)
    }

    @Test
    fun followsOneStretchWhenVisitorAndTargetAreBesideIt() {
        val path = WalkwayPlanner(listOf(walkway(1, 0.0 to 0.0, 100.0 to 0.0))).plan(at(10.0, 3.0), at(40.0, 2.0))!!
        assertEquals(3.0, path.joinMeters, 0.3)
        assertEquals(2.0, path.finishMeters, 0.3)
        assertEquals(35.0, path.lengthMeters, 0.5)
        assertTrue(path.turns.isEmpty())
    }

    @Test
    fun gpsWiggleAlongARouteIsNotATurn() {
        val wiggly = walkway(1, *(0..20).map { step -> step * 5.0 to if (step % 2 == 0) 0.0 else 1.0 }.toTypedArray())
        val path = WalkwayPlanner(listOf(wiggly)).plan(at(0.0), at(100.0))!!
        assertTrue("found ${path.turns}", path.turns.isEmpty())
    }

    @Test
    fun noPathFarFromEveryWalkway() {
        val planner = WalkwayPlanner(listOf(lShape))
        assertNull("visitor too far from any walkway", planner.plan(at(30.0, -60.0), at(60.0, 40.0)))
        assertNull("target too far from any walkway", planner.plan(at(0.0), at(30.0, 80.0)))
        assertNull(WalkwayPlanner(emptyList()).plan(at(0.0), at(60.0, 40.0)))
        assertTrue(WalkwayPlanner(emptyList()).isEmpty)
        assertTrue("a route needs two points", WalkwayPlanner(listOf(walkway(1, 0.0 to 0.0))).isEmpty)
    }

    @Test
    fun keepsToTheWayAlreadyChosenWhenTwoAreAboutEqual() {
        // A square around a building: the office at the far corner, equally far either way.
        val left = walkway(1, 0.0 to 0.0, 0.0 to -20.0, 40.0 to -20.0, 40.0 to 0.0)
        val right = walkway(2, 0.0 to 0.0, 0.0 to 20.0, 40.0 to 20.0, 40.0 to 0.0)
        val planner = WalkwayPlanner(listOf(left, right))
        val office = at(40.0, 0.0)
        val first = planner.plan(at(0.0, 0.5), office)!!
        assertTrue("starts on the right-hand way", first.points.any { GeoMath.distanceMeters(it, at(20.0, 20.0)) < 3 })
        val next = planner.plan(at(0.0, -0.5), office, first)!!
        assertTrue("stays on it", next.points.any { GeoMath.distanceMeters(it, at(20.0, 20.0)) < 3 })
        val fresh = planner.plan(at(0.0, -0.5), office)!!
        assertTrue("without the earlier path, the left is shorter", fresh.points.any { GeoMath.distanceMeters(it, at(20.0, -20.0)) < 3 })
    }

    @Test
    fun pointAtWalksAlongThePath() {
        val path = WalkwayPlanner(listOf(lShape)).plan(at(0.0), at(60.0, 40.0))
        assertNotNull(path)
        assertTrue(GeoMath.distanceMeters(path!!.pointAt(30.0), at(30.0)) < 0.5)
        assertTrue(GeoMath.distanceMeters(path.pointAt(70.0), at(60.0, 10.0)) < 0.5)
        assertEquals(path.target, path.pointAt(500.0))
        assertEquals(path.points.first(), path.pointAt(-5.0))
    }
}
