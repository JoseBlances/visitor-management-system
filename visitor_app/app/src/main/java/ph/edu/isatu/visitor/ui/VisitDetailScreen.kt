package ph.edu.isatu.visitor.ui

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.QrCode2
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import kotlinx.coroutines.delay
import ph.edu.isatu.visitor.data.AppointmentDto
import ph.edu.isatu.visitor.data.CampusMapData
import ph.edu.isatu.visitor.data.QrPassDto
import ph.edu.isatu.visitor.data.TrackingData
import ph.edu.isatu.visitor.data.VisitDto
import ph.edu.isatu.visitor.service.VisitorTrackingService

/**
 * A multi-stop visit: one pass for the gate, and each office stop with its own status.
 * After check-in it becomes the campus map, tracking the whole visit.
 */
@Composable
fun VisitDetailScreen(
    visit: VisitDto,
    tracking: TrackingData?,
    busy: Boolean,
    viewModel: AppViewModel,
    campusMap: CampusMapData? = null,
    notice: String? = null,
) {
    BackHandler(onBack = viewModel::closeVisit)
    val context = LocalContext.current
    var showCancel by remember { mutableStateOf(false) }
    val trackingAppointmentId = visit.trackingAppointmentId
    var resumingShare by remember(visit.id) { mutableStateOf(false) }
    var locationPermissionMissing by remember(visit.id) { mutableStateOf(false) }
    val permissions = remember {
        buildList {
            add(Manifest.permission.ACCESS_FINE_LOCATION)
            add(Manifest.permission.ACCESS_COARSE_LOCATION)
            if (Build.VERSION.SDK_INT >= 33) add(Manifest.permission.POST_NOTIFICATIONS)
        }.toTypedArray()
    }
    val permissionLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { result ->
        if (result[Manifest.permission.ACCESS_FINE_LOCATION] == true && trackingAppointmentId != null) {
            VisitorTrackingService.start(context, trackingAppointmentId)
            locationPermissionMissing = false
        } else {
            locationPermissionMissing = true
        }
    }

    LaunchedEffect(visit.id, visit.status, visit.qrPass != null) {
        when (visit.status) {
            "open" -> while (true) {
                // Poll quickly while the pass may be scanned at the gate; otherwise just
                // pick up office decisions.
                delay(if (visit.qrPass != null) 3_000 else 15_000)
                viewModel.pollSelectedVisit(visit.id)
            }
            "checked_in" -> {
                if (trackingAppointmentId != null) {
                    val granted = ContextCompat.checkSelfPermission(
                        context,
                        Manifest.permission.ACCESS_FINE_LOCATION,
                    ) == PackageManager.PERMISSION_GRANTED
                    if (granted) {
                        VisitorTrackingService.start(context, trackingAppointmentId)
                    } else {
                        locationPermissionMissing = true
                        permissionLauncher.launch(permissions)
                    }
                }
                while (true) {
                    delay(10_000)
                    viewModel.pollSelectedVisit(visit.id)
                }
            }
        }
    }

    // The campus map: on opening, whenever the current stop changes (an office marked its
    // meeting done), and every minute.
    LaunchedEffect(trackingAppointmentId, visit.status == "checked_in", visit.currentStopId) {
        if (visit.status != "checked_in" || trackingAppointmentId == null) return@LaunchedEffect
        while (true) {
            viewModel.loadCampusMap(trackingAppointmentId)
            delay(60_000)
        }
    }
    LaunchedEffect(resumingShare) {
        if (resumingShare) {
            delay(2_000)
            viewModel.pollSelectedVisit(visit.id)
            delay(28_000)
            resumingShare = false
        }
    }

    if (visit.status == "checked_in" && trackingAppointmentId != null) {
        val firstStop = visit.stops.firstOrNull()
        TrackingMapScreen(
            visit = CampusVisit(
                trackingAppointmentId = trackingAppointmentId,
                visitorName = firstStop?.visitor?.fullName.orEmpty().ifBlank { "Registered visitor" },
                reference = visit.visitCode,
                passPayload = visit.qrPass?.let { pass -> pass.token.ifBlank { pass.payload } },
                checkedInAt = visit.checkedInAt,
                stops = visit.stops,
                currentStopId = visit.currentStopId,
            ),
            campusMap = campusMap?.takeIf { it.appointmentId == trackingAppointmentId },
            sharing = sharingState(locationPermissionMissing, tracking, resumingShare),
            notice = notice,
            onDismissNotice = viewModel::clearBanner,
            onBack = viewModel::closeVisit,
            onRequestLocationPermission = { permissionLauncher.launch(permissions) },
            onRefreshCampusMap = { viewModel.loadCampusMap(trackingAppointmentId) },
            onStopSharing = {
                resumingShare = false
                VisitorTrackingService.stop(context, trackingAppointmentId)
                viewModel.withdrawConsent(trackingAppointmentId)
            },
            onShareAgain = {
                viewModel.resumeSharing(trackingAppointmentId) {
                    VisitorTrackingService.start(context, trackingAppointmentId)
                    resumingShare = true
                }
            },
            onArrival = { stopId, method, distance, accuracy ->
                viewModel.reportArrival(trackingAppointmentId, stopId, method, distance, accuracy)
            },
        )
        return
    }

    DetailScaffold(if (visit.qrPass != null) "Visitor pass" else "Visit details", viewModel::closeVisit) { outerModifier ->
        LazyColumn(
            modifier = outerModifier.fillMaxSize(),
            contentPadding = PaddingValues(18.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            item {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("Multi-stop visit", style = MaterialTheme.typography.headlineMedium)
                        Text("${visit.visitCode} • ${formatVisitDay(visit.visitDate)}", color = MutedInk)
                    }
                    IconButton(onClick = { viewModel.openVisit(visit.id) }, enabled = !busy) {
                        Icon(Icons.Rounded.Refresh, "Refresh")
                    }
                }
            }
            item { VisitStatusPill(visit.status) }
            if (visit.status == "completed" && visit.checkedInAt != null) {
                item { VisitSummaryCard(visit.checkedInAt, visit.completedAt, visit.checkoutMethod) }
            }
            val waitingStops = visit.stops.count { it.status == "pending_approval" }
            val actionStops = visit.stops.count { it.status == "reschedule_proposed" }
            if (visit.status == "open" && actionStops > 0) {
                item {
                    NoticeCard(
                        title = "An office suggested a different time",
                        body = "Open the highlighted stop to accept the suggested time or choose another one.",
                    )
                }
            } else if (visit.status == "open" && visit.qrPass == null && waitingStops > 0) {
                item {
                    NoticeCard(
                        title = "Waiting for office approval",
                        body = "Each office reviews its own stop. Your pass appears as soon as one office approves.",
                    )
                }
            }
            visit.qrPass?.let { pass -> item { VisitPassCard(visit, pass) } }
            item { Text("Office stops", style = MaterialTheme.typography.titleLarge, color = IsatuBlueDark) }
            itemsIndexed(visit.stops, key = { _, stop -> stop.id }) { index, stop ->
                VisitStopCard(index + 1, stop) { viewModel.openAppointment(stop.id) }
            }
            if (visit.status == "open") {
                item {
                    OutlinedButton(
                        onClick = { showCancel = true },
                        modifier = Modifier.fillMaxWidth().height(52.dp),
                        enabled = !busy,
                    ) { Text("Cancel whole visit", color = MaterialTheme.colorScheme.error) }
                }
            }
            item { Spacer(Modifier.height(18.dp)) }
        }
    }

    if (showCancel) {
        var reason by remember { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { showCancel = false },
            title = { Text("Cancel this visit?") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text("Every office on this visit will be notified and your times will be released.")
                    OutlinedTextField(reason, { reason = it.take(500) }, label = { Text("Reason (optional)") }, minLines = 2)
                }
            },
            confirmButton = {
                TextButton(onClick = {
                    showCancel = false
                    viewModel.cancelVisit(visit.id, reason)
                }) { Text("Cancel visit", color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = { TextButton(onClick = { showCancel = false }) { Text("Keep visit") } },
        )
    }
}

@Composable
private fun VisitStatusPill(status: String) {
    val (background, foreground) = when (status) {
        "open", "checked_in" -> Color(0xFFDCFCE7) to IsatuGreen
        "closed" -> Color(0xFFFEE2E2) to Color(0xFFB42318)
        else -> Color(0xFFE8EEF7) to Color(0xFF475569)
    }
    Box(Modifier.background(background, RoundedCornerShape(50)).padding(horizontal = 10.dp, vertical = 5.dp)) {
        Text(visitStatusLabel(status), color = foreground, style = MaterialTheme.typography.labelLarge)
    }
}

@Composable
private fun NoticeCard(title: String, body: String) {
    Card(
        colors = CardDefaults.cardColors(containerColor = YellowSurface),
        border = BorderStroke(1.dp, Color(0xFFF4D86C)),
        shape = RoundedCornerShape(18.dp),
    ) {
        Row(Modifier.padding(18.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Icon(Icons.Rounded.HourglassTop, contentDescription = null, tint = Color(0xFF9A6700))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(title, style = MaterialTheme.typography.titleMedium)
                Text(body, color = MutedInk)
            }
        }
    }
}

@Composable
private fun VisitStopCard(number: Int, stop: AppointmentDto, onClick: () -> Unit) {
    val needsAction = stop.status == "reschedule_proposed"
    Card(
        modifier = Modifier.fillMaxWidth().clickable(onClick = onClick),
        colors = CardDefaults.cardColors(containerColor = if (needsAction) YellowSurface else Color.White),
        border = BorderStroke(1.dp, if (needsAction) Color(0xFFF4D86C) else BorderSoft),
        shape = RoundedCornerShape(16.dp),
    ) {
        Row(Modifier.padding(15.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(Modifier.size(34.dp).clip(CircleShape).background(BlueSurface), contentAlignment = Alignment.Center) {
                Text("$number", color = IsatuBlue, fontWeight = FontWeight.SemiBold)
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(stop.office.name, style = MaterialTheme.typography.titleMedium)
                Text(
                    if (stop.visitType == "walk_in") "Walk-in" else formatTimeSpan(stop.scheduledStartAt, stop.scheduledEndAt),
                    color = MutedInk,
                    style = MaterialTheme.typography.bodyMedium,
                )
                Text(stop.subject, style = MaterialTheme.typography.bodySmall, color = MutedInk, maxLines = 1)
                StatusPill(stop.status)
            }
            Icon(Icons.Rounded.ChevronRight, contentDescription = "Open stop", tint = MutedInk)
        }
    }
}

@Composable
private fun VisitPassCard(visit: VisitDto, pass: QrPassDto) {
    val payload = pass.token.ifBlank { pass.payload }
    val bitmap = remember(payload) { generateQrBitmap(payload) }
    val approvedStops = visit.stops.filter { it.status == "approved" }
    Card(
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, BorderSoft),
        shape = RoundedCornerShape(20.dp),
    ) {
        Column(
            Modifier.fillMaxWidth().padding(20.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Box(Modifier.size(48.dp).background(BlueSurface, RoundedCornerShape(14.dp)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.QrCode2, contentDescription = null, tint = IsatuBlue)
            }
            Text("VISITOR PASS", style = MaterialTheme.typography.titleLarge, color = IsatuBlueDark)
            Text(
                if (pass.currentlyValid) "Ready to scan" else "Keep this pass ready for your first stop",
                color = if (pass.currentlyValid) IsatuGreen else IsatuOrange,
                fontWeight = FontWeight.SemiBold,
            )
            Text("Reference ${visit.visitCode}", color = MutedInk, style = MaterialTheme.typography.bodyMedium)
            HorizontalDivider(color = BorderSoft)
            Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text("Covers these approved stops", color = MutedInk, style = MaterialTheme.typography.bodyMedium)
                approvedStops.forEachIndexed { index, stop ->
                    Text(
                        if (stop.visitType == "walk_in") "${index + 1}. ${stop.office.name}"
                        else "${formatTimeOfDay(stop.scheduledStartAt)} • ${stop.office.name}",
                    )
                }
            }
            bitmap?.let {
                Card(
                    colors = CardDefaults.cardColors(containerColor = Color.White),
                    border = BorderStroke(8.dp, Color.White),
                    shape = RoundedCornerShape(12.dp),
                ) {
                    Image(it.asImageBitmap(), "Visitor QR pass", Modifier.size(238.dp))
                }
            }
            Text(
                "Security can scan this pass from ${formatDateTime(pass.validFrom)} until ${formatDateTime(pass.validUntil)}. " +
                    "One scan checks you in for every approved stop.",
                color = MutedInk,
                textAlign = TextAlign.Center,
            )
            Text("Do not share screenshots of your pass.", color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium)
        }
    }
}

private fun formatVisitDay(value: String): String = runCatching {
    java.time.LocalDate.parse(value).format(java.time.format.DateTimeFormatter.ofPattern("EEE, MMM d, yyyy", java.util.Locale.US))
}.getOrDefault(value)
