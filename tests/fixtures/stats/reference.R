# Reference results for block_catquiz_statistics statistics tests (Issue #8).
# Regenerate:  Rscript reference.R   (R >= 4.3, base packages only)
# Output: data.csv (fixture data) and reference.csv (name,value with 17 significant digits).
set.seed(20261001)
n <- 80
x1 <- round(rnorm(n, 0, 1), 4)
x2 <- round(0.5 * x1 + rnorm(n, 0, 1), 4)
grp <- sample(c("A", "B", "C"), n, replace = TRUE)
m <- round(0.6 * x1 + rnorm(n, 0, 0.8), 4)
y <- round(1 + 0.4 * x1 - 0.3 * x2 + 0.5 * m + ifelse(grp == "B", 0.7, ifelse(grp == "C", -0.4, 0)) + rnorm(n, 0, 1), 4)
b <- as.integer(runif(n) < plogis(-0.3 + 0.9 * x1 + 0.6 * x2))
y[c(3, 17, 42)] <- NA
x2[c(10, 55)] <- NA
d <- data.frame(id = 1:n, x1, x2, grp, m, y, b)
write.csv(d, "data.csv", row.names = FALSE, na = "")

out <- list()
put <- function(name, value) out[[name]] <<- value

# Descriptives (y, quantile type 7 = R default).
yy <- d$y[!is.na(d$y)]
put("desc.n", length(yy)); put("desc.missing", sum(is.na(d$y)))
put("desc.mean", mean(yy)); put("desc.sd", sd(yy)); put("desc.median", median(yy))
put("desc.q1", quantile(yy, .25)); put("desc.q3", quantile(yy, .75)); put("desc.min", min(yy)); put("desc.max", max(yy))
tab <- table(d$grp)
for (k in names(tab)) put(paste0("freq.", k), tab[[k]])

# Linear regression with categorical predictor (treatment coding, reference = first level).
cc <- d[complete.cases(d[, c("y", "x1", "x2", "grp")]), ]
fit <- lm(y ~ x1 + x2 + grp, data = cc)
s <- summary(fit); ci <- confint(fit)
for (t in rownames(s$coefficients)) {
  put(paste0("lm.", t, ".b"), s$coefficients[t, 1]); put(paste0("lm.", t, ".se"), s$coefficients[t, 2])
  put(paste0("lm.", t, ".p"), s$coefficients[t, 4])
  put(paste0("lm.", t, ".cilow"), ci[t, 1]); put(paste0("lm.", t, ".cihigh"), ci[t, 2])
}
put("lm.n", nrow(cc)); put("lm.r2", s$r.squared); put("lm.adjr2", s$adj.r.squared)
put("lm.aic", AIC(fit)); put("lm.bic", BIC(fit))
r <- quantile(residuals(fit), c(0, .25, .5, .75, 1))
put("lm.res.min", r[[1]]); put("lm.res.median", r[[3]]); put("lm.res.max", r[[5]])
put("lm.cook.over", sum(cooks.distance(fit) > 4 / nrow(cc)))

# Standardised betas and VIF in a numeric-only model.
fit2 <- lm(y ~ x1 + x2, data = cc)
put("std.x1", coef(fit2)[["x1"]] * sd(cc$x1) / sd(cc$y)); put("std.x2", coef(fit2)[["x2"]] * sd(cc$x2) / sd(cc$y))
put("vif.x1", 1 / (1 - summary(lm(x1 ~ x2, data = cc))$r.squared))

# Nested models on a common sample.
m1 <- lm(y ~ x1, data = cc); m2 <- lm(y ~ x1 + x2, data = cc)
put("nested.r2.1", summary(m1)$r.squared); put("nested.r2.2", summary(m2)$r.squared)
put("nested.p", anova(m1, m2)$`Pr(>F)`[2])

# Logistic regression.
cb <- d[complete.cases(d[, c("b", "x1", "x2")]), ]
g <- glm(b ~ x1 + x2, family = binomial, data = cb)
gs <- summary(g)$coefficients; gci <- confint.default(g)
for (t in rownames(gs)) {
  put(paste0("glm.", t, ".b"), gs[t, 1]); put(paste0("glm.", t, ".se"), gs[t, 2]); put(paste0("glm.", t, ".p"), gs[t, 4])
  put(paste0("glm.", t, ".cilow"), gci[t, 1]); put(paste0("glm.", t, ".cihigh"), gci[t, 2])
}
put("glm.n", nrow(cb)); put("glm.aic", AIC(g)); put("glm.deviance", deviance(g)); put("glm.nulldeviance", g$null.deviance)
put("glm.mcfadden", 1 - deviance(g) / g$null.deviance)

# Simple mediation path: x1 -> m -> y (with direct path x1 -> y).
cp <- d[complete.cases(d[, c("y", "x1", "m")]), ]
pa <- lm(m ~ x1, data = cp); pb <- lm(y ~ m + x1, data = cp); pc <- lm(y ~ x1, data = cp)
put("path.n", nrow(cp)); put("path.a", coef(pa)[["x1"]]); put("path.b", coef(pb)[["m"]]); put("path.cprime", coef(pb)[["x1"]])
put("path.indirect", coef(pa)[["x1"]] * coef(pb)[["m"]]); put("path.total", coef(pc)[["x1"]])
put("path.r2.m", summary(pa)$r.squared); put("path.r2.y", summary(pb)$r.squared)

# Distribution functions.
put("dist.pt.2.1.10", pt(2.1, 10)); put("dist.qt.0975.37", qt(0.975, 37)); put("dist.pnorm.m1.3", pnorm(-1.3))

res <- data.frame(name = names(out), value = sprintf("%.17g", unlist(out)))
write.csv(res, "reference.csv", row.names = FALSE, quote = FALSE)
cat("written", nrow(res), "reference values\n")
