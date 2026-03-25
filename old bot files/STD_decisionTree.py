import pandas as pd
import numpy as np
from sklearn.model_selection import train_test_split, cross_val_score, cross_validate
from sklearn.metrics import (
    accuracy_score, confusion_matrix, classification_report,
    roc_auc_score, roc_curve, precision_score, recall_score
)
from sklearn.tree import DecisionTreeClassifier, plot_tree, export_text
from sklearn.calibration import calibration_curve
import matplotlib.pyplot as plt
import joblib

# Load and preprocess the data
# ===========================

data = pd.read_excel('EHR Lewinski.xlsx') # Load data

data.columns = [ # Rename columns to English
    'Description of diagnosis', 'Test', 'Test results', 'Risk group',
    'Diagnostic code', 'Diagnosis type', 'Sexual preference',
    'Work duration', 'Paid sex', 'Condom use', 'Comments'
] 

data = data.drop(0)
data['Paid sex'] = data['Paid sex'].fillna("") # Fill NaNs in 'Paid sex' with empty string

# ===========================
# Feature Engineering
# ===========================

data['Paid sex recoded'] = data['Paid sex'].apply( # 0 = missing/no answer, 1 = NO paid sex, 2 = YES paid sex
    lambda x: 0 if x == -999 else 0 if x == '' else 1 if x == u"לא" else 2
)

data['Sexual preference recoded'] = data['Sexual preference'].apply( # 1 = bisexual, 2 = heterosexual, 3 = homosexual/other, 0 = missing
    lambda x: 0 if x == -999 else 0 if x == '' 
    else 1 if x == u"בנים + בנות" 
    else 2 if x == u"עם בן מין אחר" 
    else 3
)

data['Condom use recoded'] = data['Condom use'].apply( # 1 = condoms used, 0 = no/missing
    lambda x: 0 if x == -999 else 0 if x == '' else 1
)

data['Label'] = data['Diagnostic code'].apply( # 0 = no STD, 1 = STD positive 
    lambda x: 0 if pd.isna(x) or (isinstance(x, str) and u"אין מחלות מין" in x) else 1
)

# Encoding Risk group into integer categories
unique_risk_factors = data['Risk group'].unique()
unique_risk_factors = unique_risk_factors[~pd.isna(unique_risk_factors)]
unique_risk_factors = np.insert(unique_risk_factors, 0, np.nan)

data['Risk group indexed'] = data['Risk group'].map(
    {option: idx for idx, option in enumerate(unique_risk_factors)}
)

data = data.sample(frac=1, random_state=42).reset_index(drop=True) # Shuffle the data 

# ===========================
# Define Features and Target
# ===========================

features = ['Paid sex recoded', 'Sexual preference recoded', 'Condom use recoded', 'Risk group indexed'] 
target = 'Label' # STD yes/no

X = data[features]
y = data[target]

# ===========================
# Train-Test Split (80/20)
# ===========================

X_train, X_test, y_train, y_test = train_test_split(
    X, y, test_size=0.2, random_state=42, stratify=y
)

# ===========================
# Train Decision Tree using 3 Questions
# ===========================
tree = DecisionTreeClassifier(
    max_depth=3,
    min_samples_leaf=50,
    criterion='entropy',
    random_state=42
)

tree.fit(X_train, y_train) # Train the model
y_pred = tree.predict(X_test)

# ===========================
# Model Evaluation
# ===========================
print("\nTest Accuracy:", accuracy_score(y_test, y_pred))

# print("\n Confusion Matrix:\n", confusion_matrix(y_test, y_pred))
# print("\n Classification Report:\n", classification_report(y_test, y_pred))

# ROC-AUC
y_prob = tree.predict_proba(X_test)[:, 1]
print("ROC-AUC:", roc_auc_score(y_test, y_prob)) # ROC-AUC score

# ===========================
# Use Threshold to improve Recall
# ===========================

threshold = 0.30
y_pred_thresh = (y_prob >= threshold).astype(int)

print(f"\nThreshold-based Evaluation (threshold = {threshold})")
print("Precision:", precision_score(y_test, y_pred_thresh))
print("Recall:", recall_score(y_test, y_pred_thresh))

# print("Confusion matrix:\n", confusion_matrix(y_test, y_pred_thresh))

# ===========================
# Cross-Validation with Multiple Metrics
# ===========================
print("\nRunning Multi-Metric Cross-Validation...")
cv_results = cross_validate(
    tree, X, y, cv=5,
    scoring=['accuracy', 'precision', 'recall', 'roc_auc']
)

print("Mean CV Accuracy:", cv_results['test_accuracy'].mean())
# print("Mean CV Recall:", cv_results['test_recall'].mean())
print("Mean CV AUC:", cv_results['test_roc_auc'].mean())

# ===========================
# Show ROC Curve
# ===========================

fpr, tpr, _ = roc_curve(y_test, y_prob)
plt.plot(fpr, tpr)
plt.plot([0,1],[0,1],'--')
plt.title("ROC Curve")
plt.xlabel("False Positive Rate")
plt.ylabel("True Positive Rate")
plt.show()

# ===========================
# Feature Importance
# ===========================
importance = pd.DataFrame({
    'feature': features,
    'importance': tree.feature_importances_
}).sort_values(by='importance', ascending=False)

# print("\n Feature Importances:\n", importance)


# ===========================
# Visualize Decision Tree 
# ===========================

print("\n Decision Rules:\n")
print(export_text(tree, feature_names=features))

plt.figure(figsize=(8, 6))
plot_tree(tree, feature_names=features, class_names=['No', 'Yes'], filled=True)
plt.title("Simplified Decision Tree (3 Questions)")
plt.savefig("decision_tree.png", dpi=300, bbox_inches='tight') # Save figure
plt.show()

# ===========================
# Saving The Model
# ===========================

joblib.dump(tree, "clinic_decision_tree.pkl")
