package ar.com.securityapp.campo.sync

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import ar.com.securityapp.campo.CampoApp
import ar.com.securityapp.campo.data.ApiException
import ar.com.securityapp.campo.data.OfflineException
import java.util.concurrent.TimeUnit

/**
 * Sincroniza en segundo plano (aunque la app esté cerrada): manda la cola y baja cambios. WorkManager lo vuelve a
 * intentar solo cuando hay red, con espera creciente. Así lo hecho sin señal sale apenas vuelve la conexión.
 */
class SyncWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val c = (applicationContext as CampoApp).container
        if (!c.auth.isLoggedIn) return Result.success()
        return try {
            c.sync.syncAll()
            Result.success()
        } catch (e: OfflineException) {
            Result.retry()
        } catch (e: ApiException) {
            if (e.status >= 500) Result.retry() else Result.success() // 401: la sesión se pide de nuevo en la pantalla
        } finally {
            c.dataChanged.tryEmit(Unit)
        }
    }

    companion object {
        private val NETWORK = Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()

        /** Después de guardar algo: se manda apenas haya red (en cola detrás de la que esté corriendo). */
        fun now(context: Context) {
            val req = OneTimeWorkRequestBuilder<SyncWorker>()
                .setConstraints(NETWORK)
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 15, TimeUnit.SECONDS)
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork("sync-now", ExistingWorkPolicy.APPEND_OR_REPLACE, req)
        }

        /** Cada 15 minutos (lo mínimo que permite Android) para traer rutas y avisos nuevos. */
        fun schedulePeriodic(context: Context) {
            val req = PeriodicWorkRequestBuilder<SyncWorker>(15, TimeUnit.MINUTES).setConstraints(NETWORK).build()
            WorkManager.getInstance(context).enqueueUniquePeriodicWork("sync-periodic", ExistingPeriodicWorkPolicy.KEEP, req)
        }
    }
}
