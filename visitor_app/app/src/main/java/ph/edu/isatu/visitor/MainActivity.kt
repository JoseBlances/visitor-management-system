package ph.edu.isatu.visitor

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.lifecycle.ViewModelProvider
import ph.edu.isatu.visitor.ui.AppViewModel
import ph.edu.isatu.visitor.ui.ISATUVisitorTheme
import ph.edu.isatu.visitor.ui.VisitorApp

class MainActivity : ComponentActivity() {
    private lateinit var viewModel: AppViewModel

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        viewModel = ViewModelProvider(this, AppViewModel.Factory(application))[AppViewModel::class.java]
        setContent {
            ISATUVisitorTheme {
                VisitorApp(viewModel)
            }
        }
        openAppointmentFrom(intent)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        openAppointmentFrom(intent)
    }

    /**
     * Opens the visit or appointment a notification refers to. Notifications shown by the
     * app itself carry a Long extra; notifications Android shows from a background push
     * carry the FCM data values as String extras, so both forms are accepted.
     */
    private fun openAppointmentFrom(intent: Intent?) {
        val visitId = intent.idExtra("visit_id")
        val appointmentId = intent.idExtra("appointment_id")
        when {
            visitId > 0 -> viewModel.openVisit(visitId)
            appointmentId > 0 -> viewModel.openAppointment(appointmentId)
        }
    }

    private fun Intent?.idExtra(name: String): Long {
        val extras = this?.extras ?: return 0L
        return extras.getString(name)?.toLongOrNull() ?: extras.getLong(name, 0L)
    }
}

