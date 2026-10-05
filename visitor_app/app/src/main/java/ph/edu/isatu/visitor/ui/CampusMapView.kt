package ph.edu.isatu.visitor.ui

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Paint
import android.graphics.Path
import android.graphics.RectF
import android.graphics.Typeface
import android.location.Location
import android.view.Gravity
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.core.content.res.ResourcesCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import java.util.concurrent.CopyOnWriteArrayList
import kotlin.math.max
import kotlin.math.roundToInt
import kotlinx.coroutines.delay
import org.maplibre.android.MapLibre
import org.maplibre.android.camera.CameraPosition
import org.maplibre.android.camera.CameraUpdateFactory
import org.maplibre.android.geometry.LatLng
import org.maplibre.android.geometry.LatLngBounds
import org.maplibre.android.location.CompassEngine
import org.maplibre.android.location.CompassListener
import org.maplibre.android.location.LocationComponent
import org.maplibre.android.location.LocationComponentActivationOptions
import org.maplibre.android.location.LocationComponentOptions
import org.maplibre.android.location.OnCameraTrackingChangedListener
import org.maplibre.android.location.modes.CameraMode
import org.maplibre.android.location.modes.RenderMode
import org.maplibre.android.maps.MapLibreMap
import org.maplibre.android.maps.MapView
import org.maplibre.android.maps.Style
import org.maplibre.android.style.expressions.Expression
import org.maplibre.android.style.layers.FillLayer
import org.maplibre.android.style.layers.LineLayer
import org.maplibre.android.style.layers.Property
import org.maplibre.android.style.layers.PropertyFactory
import org.maplibre.android.style.layers.SymbolLayer
import org.maplibre.android.style.sources.GeoJsonSource
import org.maplibre.geojson.Feature
import org.maplibre.geojson.FeatureCollection
import org.maplibre.geojson.LineString
import org.maplibre.geojson.Point
import org.maplibre.geojson.Polygon
import ph.edu.isatu.visitor.BuildConfig
import ph.edu.isatu.visitor.R
import ph.edu.isatu.visitor.navigation.GeoMath
import ph.edu.isatu.visitor.navigation.GeoPoint
import ph.edu.isatu.visitor.navigation.HeadingSensor

/** A pin on the visitor's map. [key] comes back through onMarkerTapped. */
data class MapMarker(val key: String, val point: GeoPoint, val style: MarkerStyle)

sealed interface MarkerStyle {
    /** A teardrop pin with its name above it, e.g. the office the visitor is heading to. */
    data class Pin(val label: String, val color: Int) : MarkerStyle

    /** A numbered dot for another stop of a visit to several offices. */
    data class Stop(val number: Int, val done: Boolean) : MarkerStyle
}

/** What the map draws besides the base map and the visitor's own arrow. */
data class CampusMapModel(
    val boundary: List<GeoPoint> = emptyList(),
    val markers: List<MapMarker> = emptyList(),
    /** Draws the dotted pointer line from the visitor to this point (not a walking route). */
    val pointerTo: GeoPoint? = null,
)

/**
 * The walking path on the map: [walkway] along recorded walkways (a solid blue line with
 * arrows), and [connectors], the short straight legs onto and off them (dotted).
 */
data class RouteLine(val walkway: List<GeoPoint>, val connectors: List<List<GeoPoint>>)

sealed interface MapCommand {
    /** Show everything in [points] north-up, e.g. the visitor and their office. */
    data class Overview(val points: List<GeoPoint>) : MapCommand
    data object NorthUp : MapCommand
    data object RetryOnlineMap : MapCommand
}

/** A [MapCommand] with an id, so the same command can be sent twice in a row. */
data class MapCommandRequest(val id: Int, val command: MapCommand)

/**
 * The campus map: OpenFreeMap tiles through MapLibre (no API key), the campus outline with
 * the outside dimmed, office and gate pins, the walking [route] (or the dotted pointer line
 * where no walkway leads), and the visitor's arrow. While [following], the map turns with
 * the visitor (heading-up, like a driver
 * app) and keeps them in the lower part of the screen; any drag hands control back to the
 * visitor through [onFollowDismissed]. If the map tiles can't load (no connection), it
 * switches to a plain background, keeps every overlay working, and reports it through
 * [onOfflineChanged].
 */
@Composable
fun CampusMap(
    model: CampusMapModel,
    location: Location?,
    headingSensor: HeadingSensor,
    following: Boolean,
    command: MapCommandRequest?,
    topPaddingPx: Int,
    bottomPaddingPx: Int,
    onFollowDismissed: () -> Unit,
    onMarkerTapped: (String) -> Unit,
    onBearingChanged: (Float) -> Unit,
    onOfflineChanged: (Boolean) -> Unit,
    modifier: Modifier = Modifier,
    route: RouteLine? = null,
) {
    val context = LocalContext.current
    val mapView = remember {
        MapLibre.getInstance(context.applicationContext)
        MapView(context).also { it.onCreate(null) }
    }
    MapViewLifecycle(mapView)

    val session = remember { MapSession() }
    var map by remember { mutableStateOf<MapLibreMap?>(null) }
    var style by remember { mutableStateOf<Style?>(null) }
    val latestFollowDismissed by rememberUpdatedState(onFollowDismissed)
    val latestMarkerTapped by rememberUpdatedState(onMarkerTapped)
    val latestBearingChanged by rememberUpdatedState(onBearingChanged)
    val latestOfflineChanged by rememberUpdatedState(onOfflineChanged)

    fun showStyle(loadedMap: MapLibreMap, builder: Style.Builder, offline: Boolean) {
        style = null
        session.addedImages.clear()
        loadedMap.setStyle(builder) { loaded ->
            style = loaded
            latestOfflineChanged(offline)
        }
    }

    DisposableEffect(mapView) {
        // A style that fails to download (no connection) is replaced by a plain background.
        val failListener = MapView.OnDidFailLoadingMapListener {
            val loadedMap = map
            if (loadedMap != null && !session.usingFallback) {
                session.usingFallback = true
                showStyle(loadedMap, Style.Builder().fromJson(FALLBACK_STYLE_JSON), offline = true)
            }
        }
        mapView.addOnDidFailLoadingMapListener(failListener)
        mapView.getMapAsync { loadedMap ->
            loadedMap.uiSettings.setCompassEnabled(false)
            loadedMap.uiSettings.setLogoEnabled(false)
            loadedMap.uiSettings.setTiltGesturesEnabled(false)
            loadedMap.uiSettings.setAttributionEnabled(true)
            loadedMap.uiSettings.setAttributionGravity(Gravity.BOTTOM or Gravity.START)
            loadedMap.setMinZoomPreference(MIN_ZOOM)
            loadedMap.setMaxZoomPreference(MAX_ZOOM)
            loadedMap.addOnMapClickListener { latLng ->
                val screenPoint = loadedMap.projection.toScreenLocation(latLng)
                val key = loadedMap.queryRenderedFeatures(screenPoint, LAYER_MARKERS)
                    .firstOrNull { it.hasProperty(PROP_KEY) }
                    ?.getStringProperty(PROP_KEY)
                if (key != null) latestMarkerTapped(key)
                key != null
            }
            loadedMap.addOnCameraMoveListener {
                val bearing = (loadedMap.cameraPosition.bearing / 5).roundToInt() * 5f
                if (bearing != session.reportedBearing) {
                    session.reportedBearing = bearing
                    latestBearingChanged(bearing)
                }
            }
            map = loadedMap
            showStyle(loadedMap, Style.Builder().fromUri(BuildConfig.MAP_STYLE_URL), offline = false)
        }
        onDispose { mapView.removeOnDidFailLoadingMapListener(failListener) }
    }

    // A style that hangs on a very slow connection also falls back after a while.
    LaunchedEffect(map) {
        val loadedMap = map ?: return@LaunchedEffect
        delay(STYLE_TIMEOUT_MILLIS)
        if (style == null && !session.usingFallback) {
            session.usingFallback = true
            showStyle(loadedMap, Style.Builder().fromJson(FALLBACK_STYLE_JSON), offline = true)
        }
    }

    // Each newly loaded style gets the campus layers and the visitor's arrow.
    LaunchedEffect(style) {
        val loadedMap = map ?: return@LaunchedEffect
        val loadedStyle = style ?: return@LaunchedEffect
        addCampusLayers(context, loadedStyle)
        activateLocation(context, loadedMap, loadedStyle, headingSensor) { latestFollowDismissed() }
        if (!session.cameraPlaced) {
            session.cameraPlaced = true
            placeInitialCamera(loadedMap, model.boundary, location, topPaddingPx, bottomPaddingPx)
        }
    }

    LaunchedEffect(style, model) {
        val loadedStyle = style ?: return@LaunchedEffect
        loadedStyle.getSourceAs<GeoJsonSource>(SOURCE_BOUNDARY)?.setGeoJson(boundaryFeatures(model.boundary))
        loadedStyle.getSourceAs<GeoJsonSource>(SOURCE_MASK)?.setGeoJson(maskFeatures(model.boundary))
        val features = model.markers.map { marker ->
            val image = markerImageId(marker.style)
            if (session.addedImages.add(image)) loadedStyle.addImage(image, markerBitmap(context, marker.style))
            Feature.fromGeometry(Point.fromLngLat(marker.point.longitude, marker.point.latitude)).apply {
                addStringProperty(PROP_KEY, marker.key)
                addStringProperty(PROP_ICON, image)
                addStringProperty(PROP_ANCHOR, if (marker.style is MarkerStyle.Pin) Property.ICON_ANCHOR_BOTTOM else "center")
            }
        }
        loadedStyle.getSourceAs<GeoJsonSource>(SOURCE_MARKERS)?.setGeoJson(FeatureCollection.fromFeatures(features))
    }

    LaunchedEffect(style, location, model.pointerTo) {
        val loadedStyle = style ?: return@LaunchedEffect
        val from = location
        val to = model.pointerTo
        val line = if (from != null && to != null) {
            FeatureCollection.fromFeatures(
                listOf(
                    Feature.fromGeometry(
                        LineString.fromLngLats(
                            listOf(Point.fromLngLat(from.longitude, from.latitude), Point.fromLngLat(to.longitude, to.latitude)),
                        ),
                    ),
                ),
            )
        } else {
            FeatureCollection.fromFeatures(emptyList<Feature>())
        }
        loadedStyle.getSourceAs<GeoJsonSource>(SOURCE_POINTER)?.setGeoJson(line)
    }

    LaunchedEffect(style, route) {
        val loadedStyle = style ?: return@LaunchedEffect
        loadedStyle.getSourceAs<GeoJsonSource>(SOURCE_ROUTE)?.setGeoJson(lineFeatures(listOfNotNull(route?.walkway)))
        loadedStyle.getSourceAs<GeoJsonSource>(SOURCE_ROUTE_LINKS)?.setGeoJson(lineFeatures(route?.connectors.orEmpty()))
    }

    LaunchedEffect(style, location) {
        val component = map?.locationComponent ?: return@LaunchedEffect
        val fix = location ?: return@LaunchedEffect
        if (style != null && component.isLocationComponentActivated && component.isLocationComponentEnabled) {
            component.forceLocationUpdate(fix)
        }
    }

    LaunchedEffect(style, following, location != null) {
        val component = map?.locationComponent ?: return@LaunchedEffect
        if (style == null || !component.isLocationComponentActivated || !component.isLocationComponentEnabled) return@LaunchedEffect
        if (following) {
            if (location == null) return@LaunchedEffect
            component.setCameraMode(if (headingSensor.available) CameraMode.TRACKING_COMPASS else CameraMode.TRACKING_GPS)
            component.zoomWhileTracking(FOLLOW_ZOOM, CAMERA_ANIMATION_MILLIS.toLong())
        } else if (component.cameraMode != CameraMode.NONE) {
            component.setCameraMode(CameraMode.NONE)
        }
    }

    LaunchedEffect(map, style, topPaddingPx, bottomPaddingPx) {
        val loadedMap = map ?: return@LaunchedEffect
        loadedMap.uiSettings.setAttributionMargins(dp(context, 12), 0, 0, bottomPaddingPx + dp(context, 6))
        val component = loadedMap.locationComponent
        if (style != null && component.isLocationComponentActivated) {
            // Keep the visitor in the lower part of the free space, so more lies ahead.
            val freeHeight = max(0, loadedMap.height.roundToInt() - topPaddingPx - bottomPaddingPx)
            component.paddingWhileTracking(
                doubleArrayOf(0.0, topPaddingPx + freeHeight * 0.3, 0.0, bottomPaddingPx.toDouble()),
            )
        }
    }

    LaunchedEffect(command?.id) {
        val request = command ?: return@LaunchedEffect
        val loadedMap = map ?: return@LaunchedEffect
        when (val action = request.command) {
            is MapCommand.Overview -> {
                stopTracking(loadedMap)
                showPoints(loadedMap, action.points, topPaddingPx, bottomPaddingPx, dp(context, 48))
            }
            MapCommand.NorthUp -> loadedMap.animateCamera(CameraUpdateFactory.bearingTo(0.0), CAMERA_ANIMATION_MILLIS)
            MapCommand.RetryOnlineMap -> {
                session.usingFallback = false
                showStyle(loadedMap, Style.Builder().fromUri(BuildConfig.MAP_STYLE_URL), offline = false)
            }
        }
    }

    AndroidView(factory = { mapView }, modifier = modifier)
}

/** Forwards the screen's lifecycle to the MapView, which needs every step. */
@Composable
private fun MapViewLifecycle(mapView: MapView) {
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    DisposableEffect(lifecycle, mapView) {
        var lastEvent = Lifecycle.Event.ON_CREATE
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_START -> mapView.onStart()
                Lifecycle.Event.ON_RESUME -> mapView.onResume()
                Lifecycle.Event.ON_PAUSE -> mapView.onPause()
                Lifecycle.Event.ON_STOP -> mapView.onStop()
                else -> Unit
            }
            if (event != Lifecycle.Event.ON_DESTROY) lastEvent = event
        }
        lifecycle.addObserver(observer)
        onDispose {
            lifecycle.removeObserver(observer)
            when (lastEvent) {
                Lifecycle.Event.ON_RESUME -> {
                    mapView.onPause()
                    mapView.onStop()
                }
                Lifecycle.Event.ON_START, Lifecycle.Event.ON_PAUSE -> mapView.onStop()
                else -> Unit
            }
            mapView.onDestroy()
        }
    }
}

/** Per-map state that must not trigger recomposition. */
private class MapSession {
    val addedImages = mutableSetOf<String>()
    var usingFallback = false
    var cameraPlaced = false
    var reportedBearing = 0f
}

private fun addCampusLayers(context: Context, style: Style) {
    val empty = FeatureCollection.fromFeatures(emptyList<Feature>())
    if (style.getSourceAs<GeoJsonSource>(SOURCE_MASK) == null) style.addSource(GeoJsonSource(SOURCE_MASK, empty))
    if (style.getSourceAs<GeoJsonSource>(SOURCE_BOUNDARY) == null) style.addSource(GeoJsonSource(SOURCE_BOUNDARY, empty))
    if (style.getSourceAs<GeoJsonSource>(SOURCE_POINTER) == null) style.addSource(GeoJsonSource(SOURCE_POINTER, empty))
    if (style.getSourceAs<GeoJsonSource>(SOURCE_ROUTE) == null) style.addSource(GeoJsonSource(SOURCE_ROUTE, empty))
    if (style.getSourceAs<GeoJsonSource>(SOURCE_ROUTE_LINKS) == null) style.addSource(GeoJsonSource(SOURCE_ROUTE_LINKS, empty))
    if (style.getSourceAs<GeoJsonSource>(SOURCE_MARKERS) == null) style.addSource(GeoJsonSource(SOURCE_MARKERS, empty))
    if (style.getImage(ROUTE_ARROW_IMAGE) == null) style.addImage(ROUTE_ARROW_IMAGE, routeArrowBitmap(context))
    if (style.getLayer(LAYER_MASK) == null) {
        style.addLayer(
            FillLayer(LAYER_MASK, SOURCE_MASK).withProperties(
                PropertyFactory.fillColor("#0F172A"),
                PropertyFactory.fillOpacity(0.10f),
            ),
        )
    }
    if (style.getLayer(LAYER_BOUNDARY) == null) {
        style.addLayer(
            LineLayer(LAYER_BOUNDARY, SOURCE_BOUNDARY).withProperties(
                PropertyFactory.lineColor(BRAND_BLUE),
                PropertyFactory.lineWidth(3f),
                PropertyFactory.lineOpacity(0.9f),
                PropertyFactory.lineJoin(Property.LINE_JOIN_ROUND),
            ),
        )
    }
    if (style.getLayer(LAYER_POINTER) == null) {
        style.addLayer(
            LineLayer(LAYER_POINTER, SOURCE_POINTER).withProperties(
                PropertyFactory.lineColor(BRAND_BLUE),
                PropertyFactory.lineWidth(4.5f),
                PropertyFactory.lineDasharray(arrayOf(0.1f, 1.9f)),
                PropertyFactory.lineCap(Property.LINE_CAP_ROUND),
                PropertyFactory.lineOpacity(0.95f),
            ),
        )
    }
    // The walking path: a white edge under a bright blue line (unlike the darker campus
    // outline), arrows along it toward the target, and dotted legs onto and off the walkway.
    if (style.getLayer(LAYER_ROUTE_LINKS) == null) {
        style.addLayer(
            LineLayer(LAYER_ROUTE_LINKS, SOURCE_ROUTE_LINKS).withProperties(
                PropertyFactory.lineColor(ROUTE_BLUE),
                PropertyFactory.lineWidth(4.5f),
                PropertyFactory.lineDasharray(arrayOf(0.1f, 1.9f)),
                PropertyFactory.lineCap(Property.LINE_CAP_ROUND),
            ),
        )
    }
    if (style.getLayer(LAYER_ROUTE_CASING) == null) {
        style.addLayer(
            LineLayer(LAYER_ROUTE_CASING, SOURCE_ROUTE).withProperties(
                PropertyFactory.lineColor("#FFFFFF"),
                PropertyFactory.lineWidth(10f),
                PropertyFactory.lineJoin(Property.LINE_JOIN_ROUND),
                PropertyFactory.lineCap(Property.LINE_CAP_ROUND),
            ),
        )
    }
    if (style.getLayer(LAYER_ROUTE) == null) {
        style.addLayer(
            LineLayer(LAYER_ROUTE, SOURCE_ROUTE).withProperties(
                PropertyFactory.lineColor(ROUTE_BLUE),
                PropertyFactory.lineWidth(6.5f),
                PropertyFactory.lineJoin(Property.LINE_JOIN_ROUND),
                PropertyFactory.lineCap(Property.LINE_CAP_ROUND),
            ),
        )
    }
    if (style.getLayer(LAYER_ROUTE_ARROWS) == null) {
        style.addLayer(
            SymbolLayer(LAYER_ROUTE_ARROWS, SOURCE_ROUTE).withProperties(
                PropertyFactory.symbolPlacement(Property.SYMBOL_PLACEMENT_LINE),
                PropertyFactory.symbolSpacing(46f),
                PropertyFactory.iconImage(ROUTE_ARROW_IMAGE),
                PropertyFactory.iconRotationAlignment(Property.ICON_ROTATION_ALIGNMENT_MAP),
                PropertyFactory.iconAllowOverlap(true),
                PropertyFactory.iconIgnorePlacement(true),
            ),
        )
    }
    if (style.getLayer(LAYER_MARKERS) == null) {
        style.addLayer(
            SymbolLayer(LAYER_MARKERS, SOURCE_MARKERS).withProperties(
                PropertyFactory.iconImage(Expression.get(PROP_ICON)),
                PropertyFactory.iconAnchor(Expression.get(PROP_ANCHOR)),
                PropertyFactory.iconAllowOverlap(true),
                PropertyFactory.iconIgnorePlacement(true),
                PropertyFactory.symbolZOrder(Property.SYMBOL_Z_ORDER_SOURCE),
            ),
        )
    }
}

private fun activateLocation(
    context: Context,
    map: MapLibreMap,
    style: Style,
    headingSensor: HeadingSensor,
    onFollowDismissed: () -> Unit,
) {
    val component = map.locationComponent
    if (!component.isLocationComponentActivated) {
        val blue = IsatuBlue.toArgb()
        // No accuracy circle around the arrow: the banner shows the accuracy as "GPS ±4 m"
        // instead, so the circle is never mistaken for the arrival area.
        val options = LocationComponentOptions.builder(context)
            .foregroundTintColor(blue)
            .bearingTintColor(blue)
            .accuracyAlpha(0f)
            .accuracyAnimationEnabled(false)
            .enableStaleState(true)
            .staleStateTimeout(STALE_MILLIS)
            .trackingGesturesManagement(true)
            .build()
        component.activateLocationComponent(
            LocationComponentActivationOptions.Builder(context, style)
                .useDefaultLocationEngine(false)
                .locationComponentOptions(options)
                .build(),
        )
        component.setCompassEngine(HeadingCompassEngine(headingSensor))
        component.addOnCameraTrackingChangedListener(object : OnCameraTrackingChangedListener {
            override fun onCameraTrackingDismissed() = onFollowDismissed()
            override fun onCameraTrackingChanged(currentMode: Int) = Unit
        })
    }
    if (ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) ==
        PackageManager.PERMISSION_GRANTED
    ) {
        component.setLocationComponentEnabled(true)
        component.setRenderMode(RenderMode.COMPASS)
    }
}

private fun stopTracking(map: MapLibreMap) {
    val component: LocationComponent = map.locationComponent
    if (component.isLocationComponentActivated && component.cameraMode != CameraMode.NONE) {
        component.setCameraMode(CameraMode.NONE)
    }
}

private fun placeInitialCamera(map: MapLibreMap, boundary: List<GeoPoint>, location: Location?, top: Int, bottom: Int) {
    when {
        location != null -> map.moveCamera(
            CameraUpdateFactory.newLatLngZoom(LatLng(location.latitude, location.longitude), FOLLOW_ZOOM),
        )
        boundary.size >= 3 -> map.moveCamera(boundsUpdate(boundary, top, bottom, 32) ?: return)
        else -> map.moveCamera(CameraUpdateFactory.newLatLngZoom(LatLng(DEFAULT_LATITUDE, DEFAULT_LONGITUDE), 16.0))
    }
}

private fun showPoints(map: MapLibreMap, points: List<GeoPoint>, top: Int, bottom: Int, side: Int) {
    val update = boundsUpdate(points, top, bottom, side)
    if (update != null) {
        map.animateCamera(update, CAMERA_ANIMATION_MILLIS)
    } else if (points.isNotEmpty()) {
        val point = points.first()
        map.animateCamera(
            CameraUpdateFactory.newCameraPosition(
                CameraPosition.Builder().target(LatLng(point.latitude, point.longitude)).zoom(FOLLOW_ZOOM).bearing(0.0).tilt(0.0).build(),
            ),
            CAMERA_ANIMATION_MILLIS,
        )
    }
}

/** North-up camera fitting [points], or null when they are all in one spot. */
private fun boundsUpdate(points: List<GeoPoint>, top: Int, bottom: Int, side: Int) =
    if (points.size < 2 || points.all { GeoMath.distanceMeters(it, points.first()) < 15 }) {
        null
    } else {
        val bounds = LatLngBounds.Builder().includes(points.map { LatLng(it.latitude, it.longitude) }).build()
        CameraUpdateFactory.newLatLngBounds(bounds, 0.0, 0.0, side, top + side, side, bottom + side)
    }

/** One line feature per polyline with at least two points. */
private fun lineFeatures(lines: List<List<GeoPoint>>): FeatureCollection =
    FeatureCollection.fromFeatures(
        lines.filter { it.size >= 2 }.map { line ->
            Feature.fromGeometry(LineString.fromLngLats(line.map { Point.fromLngLat(it.longitude, it.latitude) }))
        },
    )

/** A white chevron pointing along the line; MapLibre turns it with the line it sits on. */
private fun routeArrowBitmap(context: Context): Bitmap {
    val density = context.resources.displayMetrics.density
    val size = max(8, (12 * density).roundToInt())
    val bitmap = Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888)
    val paint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = android.graphics.Color.WHITE
        style = Paint.Style.STROKE
        strokeWidth = 2.2f * density
        strokeCap = Paint.Cap.ROUND
        strokeJoin = Paint.Join.ROUND
    }
    val chevron = Path().apply {
        moveTo(size * 0.34f, size * 0.22f)
        lineTo(size * 0.66f, size * 0.5f)
        lineTo(size * 0.34f, size * 0.78f)
    }
    Canvas(bitmap).drawPath(chevron, paint)
    return bitmap
}

private fun boundaryFeatures(boundary: List<GeoPoint>): FeatureCollection {
    if (boundary.size < 3) return FeatureCollection.fromFeatures(emptyList<Feature>())
    val ring = boundary.map { Point.fromLngLat(it.longitude, it.latitude) }
    return FeatureCollection.fromFeatures(listOf(Feature.fromGeometry(LineString.fromLngLats(ring + ring.first()))))
}

/** A large rectangle with the campus cut out, so everything outside is dimmed. */
private fun maskFeatures(boundary: List<GeoPoint>): FeatureCollection {
    if (boundary.size < 3) return FeatureCollection.fromFeatures(emptyList<Feature>())
    val minLat = boundary.minOf { it.latitude } - MASK_MARGIN_DEGREES
    val maxLat = boundary.maxOf { it.latitude } + MASK_MARGIN_DEGREES
    val minLng = boundary.minOf { it.longitude } - MASK_MARGIN_DEGREES
    val maxLng = boundary.maxOf { it.longitude } + MASK_MARGIN_DEGREES
    val outer = listOf(
        Point.fromLngLat(minLng, minLat),
        Point.fromLngLat(maxLng, minLat),
        Point.fromLngLat(maxLng, maxLat),
        Point.fromLngLat(minLng, maxLat),
        Point.fromLngLat(minLng, minLat),
    )
    // The renderer treats a ring wound the same way as the outer ring as a new shape, so
    // the hole must run the opposite way (the outer ring above is counter-clockwise).
    val ring = boundary.map { Point.fromLngLat(it.longitude, it.latitude) }
    val hole = if (signedArea(ring) > 0) ring.reversed() else ring
    return FeatureCollection.fromFeatures(
        listOf(Feature.fromGeometry(Polygon.fromLngLats(listOf(outer, hole + hole.first())))),
    )
}

private fun signedArea(ring: List<Point>): Double {
    var sum = 0.0
    for (index in ring.indices) {
        val a = ring[index]
        val b = ring[(index + 1) % ring.size]
        sum += a.longitude() * b.latitude() - b.longitude() * a.latitude()
    }
    return sum / 2
}

private fun markerImageId(style: MarkerStyle): String = when (style) {
    is MarkerStyle.Pin -> "isatu-pin|${style.color}|${style.label}"
    is MarkerStyle.Stop -> "isatu-stop|${style.number}|${style.done}"
}

private fun markerBitmap(context: Context, style: MarkerStyle): Bitmap = when (style) {
    is MarkerStyle.Pin -> pinBitmap(context, style.label, style.color)
    is MarkerStyle.Stop -> stopBitmap(context, style.number, style.done)
}

private fun markerTypeface(context: Context): Typeface =
    runCatching { ResourcesCompat.getFont(context, R.font.poppins_semibold) }.getOrNull() ?: Typeface.DEFAULT_BOLD

/** A coloured teardrop pin with a white name tag above it; the tip is the bottom centre. */
private fun pinBitmap(context: Context, rawLabel: String, color: Int): Bitmap {
    val density = context.resources.displayMetrics.density
    val label = if (rawLabel.length > 28) rawLabel.take(27).trimEnd() + "…" else rawLabel
    val textPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        typeface = markerTypeface(context)
        textSize = 12.5f * density
        this.color = InkArgb
    }
    val shadow = 3f * density
    val padX = 9f * density
    val padY = 4f * density
    val metrics = textPaint.fontMetrics
    val textHeight = metrics.descent - metrics.ascent
    val tagWidth = textPaint.measureText(label) + padX * 2
    val tagHeight = textHeight + padY * 2
    val radius = 13f * density
    val gap = 4f * density
    val pinHeight = radius * 2.55f
    val width = max(tagWidth, radius * 2) + shadow * 2
    val height = shadow + tagHeight + gap + pinHeight
    val bitmap = Bitmap.createBitmap(width.toInt() + 1, height.toInt() + 1, Bitmap.Config.ARGB_8888)
    val canvas = Canvas(bitmap)
    val centerX = bitmap.width / 2f

    val tagPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        this.color = android.graphics.Color.WHITE
        setShadowLayer(shadow, 0f, density, 0x40000000)
    }
    val tag = RectF(centerX - tagWidth / 2, shadow, centerX + tagWidth / 2, shadow + tagHeight)
    canvas.drawRoundRect(tag, tagHeight / 2, tagHeight / 2, tagPaint)
    canvas.drawText(label, tag.left + padX, tag.top + padY - metrics.ascent, textPaint)

    val circleY = tag.bottom + gap + radius
    val tipY = bitmap.height.toFloat() - 1
    val pinPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply { this.color = color }
    val pin = Path().apply {
        addCircle(centerX, circleY, radius, Path.Direction.CW)
        moveTo(centerX - radius * 0.66f, circleY + radius * 0.6f)
        lineTo(centerX + radius * 0.66f, circleY + radius * 0.6f)
        lineTo(centerX, tipY)
        close()
    }
    canvas.drawPath(pin, pinPaint)
    val dotPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply { this.color = android.graphics.Color.WHITE }
    canvas.drawCircle(centerX, circleY, radius * 0.4f, dotPaint)
    return bitmap
}

/** A small numbered dot: white with a blue ring for stops ahead, grey once done. */
private fun stopBitmap(context: Context, number: Int, done: Boolean): Bitmap {
    val density = context.resources.displayMetrics.density
    val radius = 11f * density
    val stroke = 2.5f * density
    val size = ((radius + stroke) * 2).toInt() + 2
    val bitmap = Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888)
    val canvas = Canvas(bitmap)
    val center = size / 2f
    val fill = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        this.color = if (done) 0xFF94A3B8.toInt() else android.graphics.Color.WHITE
    }
    val ring = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        this.color = if (done) 0xFF94A3B8.toInt() else IsatuBlue.toArgb()
        style = Paint.Style.STROKE
        strokeWidth = stroke
    }
    canvas.drawCircle(center, center, radius, fill)
    canvas.drawCircle(center, center, radius, ring)
    val text = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        typeface = markerTypeface(context)
        textSize = 11.5f * density
        textAlign = Paint.Align.CENTER
        this.color = if (done) android.graphics.Color.WHITE else IsatuBlue.toArgb()
    }
    val metrics = text.fontMetrics
    canvas.drawText(number.toString(), center, center - (metrics.ascent + metrics.descent) / 2, text)
    return bitmap
}

/** Feeds the shared heading sensor into MapLibre's arrow and heading-up camera. */
private class HeadingCompassEngine(private val sensor: HeadingSensor) : CompassEngine {
    private val listeners = CopyOnWriteArrayList<CompassListener>()
    private val onHeading: (Float) -> Unit = { heading -> listeners.forEach { it.onCompassChanged(heading) } }
    private val onAccuracy: (Int) -> Unit = { status -> listeners.forEach { it.onCompassAccuracyChange(status) } }

    override fun addCompassListener(listener: CompassListener) {
        if (listeners.isEmpty()) sensor.addListener(onHeading, onAccuracy)
        listeners.addIfAbsent(listener)
    }

    override fun removeCompassListener(listener: CompassListener) {
        listeners.remove(listener)
        if (listeners.isEmpty()) sensor.removeListener(onHeading, onAccuracy)
    }

    override fun getLastHeading(): Float = sensor.heading.value ?: 0f

    override fun getLastAccuracySensorStatus(): Int = sensor.accuracyStatus
}

private fun dp(context: Context, value: Int): Int = (value * context.resources.displayMetrics.density).roundToInt()

private const val SOURCE_MASK = "isatu-campus-mask"
private const val SOURCE_BOUNDARY = "isatu-campus-boundary"
private const val SOURCE_POINTER = "isatu-pointer"
private const val SOURCE_MARKERS = "isatu-markers"
private const val SOURCE_ROUTE = "isatu-route"
private const val SOURCE_ROUTE_LINKS = "isatu-route-links"
private const val LAYER_MASK = "isatu-campus-mask-fill"
private const val LAYER_BOUNDARY = "isatu-campus-boundary-line"
private const val LAYER_POINTER = "isatu-pointer-line"
private const val LAYER_ROUTE_LINKS = "isatu-route-link-line"
private const val LAYER_ROUTE_CASING = "isatu-route-casing"
private const val LAYER_ROUTE = "isatu-route-line"
private const val LAYER_ROUTE_ARROWS = "isatu-route-arrows"
private const val LAYER_MARKERS = "isatu-marker-symbols"
private const val ROUTE_ARROW_IMAGE = "isatu-route-arrow"
/** Brighter than the campus outline, so the path to follow stands out. */
private const val ROUTE_BLUE = "#2563EB"
private const val PROP_KEY = "key"
private const val PROP_ICON = "icon"
private const val PROP_ANCHOR = "anchor"
private const val BRAND_BLUE = "#0757B9"
private val InkArgb = Ink.toArgb()
private const val MIN_ZOOM = 13.0
private const val MAX_ZOOM = 20.0
private const val FOLLOW_ZOOM = 18.0
private const val CAMERA_ANIMATION_MILLIS = 700
private const val STALE_MILLIS = 30_000L
private const val STYLE_TIMEOUT_MILLIS = 15_000L
private const val MASK_MARGIN_DEGREES = 1.0
/** ISATU's main campus, used before the campus outline or a position is known. */
private const val DEFAULT_LATITUDE = 10.7177
private const val DEFAULT_LONGITUDE = 122.5559

/** Shown when the online map can't load: a plain background, so overlays still work. */
private const val FALLBACK_STYLE_JSON =
    """{"version":8,"name":"Offline campus","sources":{},"layers":[{"id":"background","type":"background","paint":{"background-color":"#EEF2F7"}}]}"""
